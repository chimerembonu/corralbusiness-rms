<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Documents\ReceiptDocument;
use App\Modules\Printing\Drivers\NetworkEscPos;
use App\Modules\Printing\Enums\PrinterDriverType;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\PrintJobType;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Jobs\ProcessPrintJob;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\EscPosRenderer;
use App\Modules\Printing\Services\PrinterDriverRegistry;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Queue;

class FakeNetworkDriver implements PrinterDriver
{
    public static array $printed = [];

    public static bool $fail = false;

    public function print(Printer $printer, PrintDocument $document): void
    {
        if (self::$fail) {
            throw new PrintFailedException('Printer unplugged');
        }
        self::$printed[] = [$printer->name, $document->title];
    }

    public function completesImmediately(): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->branch = provisionTenant();
    FakeNetworkDriver::$printed = [];
    FakeNetworkDriver::$fail = false;
    app(PrinterDriverRegistry::class)->register(PrinterDriverType::Network, FakeNetworkDriver::class);
});

it('renders a document to ESC/POS bytes', function () {
    $doc = PrintDocument::make('t')->center()->bold()->big()->text('CHOP BAR')->normal()->row('1 x Jollof', '60.00')->rule()->feed(2)->cut()->drawer();
    $bytes = (new EscPosRenderer)->render($doc, 80);

    expect($bytes)->toStartWith("\x1b@")                     // initialise
        ->and($bytes)->toContain("\x1bE\x01")                // bold on
        ->and($bytes)->toContain("\x1ba\x01")                // centre
        ->and($bytes)->toContain('CHOP BAR')
        ->and($bytes)->toContain(str_repeat('-', 48))
        ->and($bytes)->toContain("\x1dV")                    // cut
        ->and($bytes)->toContain("\x1bp")                    // drawer kick
        ->and($bytes)->toMatch('/1 x Jollof {2,}60\.00/');

    expect((new EscPosRenderer)->render($doc, 58))->toContain(str_repeat('-', 32));
});

it('builds a receipt document from a paid order with tax lines and change', function () {
    $menu = demoMenu();
    $cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::Takeaway, $cashier);
    $orders->addItem($order, $menu['jollof'], $menu['regular'], 1, [$menu['chicken']->id => 1], 'no pepper');
    app(PaymentService::class)->payCash($order, Money::fromDecimal('100'), $cashier);

    $doc = ReceiptDocument::fromOrder($order->fresh(), 'TIN 123', 'Thanks!');
    $text = collect($doc->lines())->map(fn ($l) => ($l['text'] ?? '').($l['left'] ?? '').' '.($l['right'] ?? ''))->implode("\n");

    expect($text)->toContain('Test Chop Bar', 'TIN 123', '1 x Jollof Rice (Regular) 60.00', '+ Chicken (15.00)', '* no pepper',
        'NHIL 2.5% 1.50', 'GETFund Levy 2.5% 1.50', 'VAT 15% 9.00', 'TOTAL GHS 72.00', 'Cash 72.00', 'Cash received 100.00', 'Change 28.00', 'Kofi', 'Thanks!')
        ->and(collect($doc->lines())->last()['type'])->toBe('cut');
});

it('queues a test print and the worker sends it to the driver', function () {
    $printer = Printer::create(['name' => 'Counter', 'driver' => 'network', 'address' => '192.168.8.50']);

    Queue::fake();
    $job = app(PrintService::class)->testPrint($printer);
    Queue::assertPushed(ProcessPrintJob::class, fn ($j) => $j->printJobId === $job->id);
    expect($job->status)->toBe(PrintJobStatus::Queued)->and($job->type)->toBe(PrintJobType::Test);

    (new ProcessPrintJob($job->id))->handle(app(PrinterDriverRegistry::class));

    expect($job->fresh()->status)->toBe(PrintJobStatus::Printed)
        ->and($job->fresh()->printed_at)->not->toBeNull()
        ->and(FakeNetworkDriver::$printed)->toBe([['Counter', 'Printer test page']]);
});

it('marks a job failed after the retries are used up and lets you retry it', function () {
    $printer = Printer::create(['name' => 'Counter', 'driver' => 'network', 'address' => '192.168.8.50']);
    FakeNetworkDriver::$fail = true;
    // The queue is "sync" in tests: the job runs at once, fails, and the sync queue reports the failure.
    expect(fn () => app(PrintService::class)->testPrint($printer))->toThrow(PrintFailedException::class);
    $job = PrintJob::sole();

    expect($job->fresh()->status)->toBe(PrintJobStatus::Failed)
        ->and($job->fresh()->last_error)->toBe('Printer unplugged')
        ->and(app(PrintService::class)->recentFailures($this->branch->id))->toHaveCount(1);

    FakeNetworkDriver::$fail = false;
    app(PrintService::class)->retry($job->fresh());
    expect($job->fresh()->status)->toBe(PrintJobStatus::Printed)
        ->and(app(PrintService::class)->recentFailures($this->branch->id))->toHaveCount(0);
});

it('leaves browser jobs waiting for a till, which fetches and confirms them', function () {
    seedRoles();
    $printer = Printer::create(['name' => 'Till printer', 'driver' => 'browser']);
    $job = app(PrintService::class)->testPrint($printer);
    expect($job->fresh()->status)->toBe(PrintJobStatus::WaitingBrowser);

    $cashier = User::factory()->forBranch($this->branch)->create();
    $cashier->assignRole(Role::Cashier->value);
    $this->actingAs($cashier);

    $this->getJson('/print/next')->assertOk()->assertJsonPath('job.id', $job->id);
    $this->get("/print/jobs/{$job->id}?print=1")->assertOk()->assertSee('Printer test page')->assertSee('window.print()', false)->assertSee('size: 80mm', false);
    $this->postJson("/print/jobs/{$job->id}/printed")->assertOk();

    expect($job->fresh()->status)->toBe(PrintJobStatus::Printed);
    $this->getJson('/print/next')->assertOk()->assertJsonPath('job', null);
});

it('reports an unreachable network printer clearly', function () {
    $printer = Printer::create(['name' => 'Ghost', 'driver' => 'network', 'address' => '127.0.0.1:1']);

    expect(fn () => (new NetworkEscPos(new EscPosRenderer, 1))->print($printer, PrintDocument::make()->text('hi')))
        ->toThrow(PrintFailedException::class, 'Cannot reach printer at 127.0.0.1:1');
});

it('prints a receipt on every receipt printer when an order is paid', function () {
    $menu = demoMenu();
    Printer::create(['name' => 'Counter', 'driver' => 'network', 'address' => '192.168.8.50', 'prints_receipts' => true]);
    Printer::create(['name' => 'Kitchen', 'driver' => 'network', 'address' => '192.168.8.51', 'prints_kitchen_tickets' => true]);
    $cashier = User::factory()->forBranch($this->branch)->create();
    $order = app(OrderService::class)->open(OrderType::Takeaway, $cashier);
    app(OrderService::class)->addItem($order, $menu['coke']);

    app(PaymentService::class)->payCash($order, Money::fromDecimal('20'), $cashier);

    expect(PrintJob::where('type', 'receipt')->count())->toBe(1)
        ->and(PrintJob::where('type', 'receipt')->first()->printer->name)->toBe('Counter')
        ->and(PrintJob::where('type', 'kitchen_ticket')->count())->toBe(1) // paying sends pending lines to the kitchen
        ->and(collect(FakeNetworkDriver::$printed)->pluck(0)->sort()->values()->all())->toBe(['Counter', 'Kitchen']);
});

it('shows the printer settings and print jobs in the back office with a test print button', function () {
    seedRoles();
    $owner = User::factory()->forBranch($this->branch)->create();
    $owner->assignRole(Role::Owner->value);
    Printer::create(['name' => 'Counter', 'driver' => 'browser']);

    $this->actingAs($owner);
    $this->get('/admin/printers')->assertOk()->assertSee('Counter')->assertSee('Test print');
    $this->get('/admin/printers/create')->assertOk();
    $this->get('/admin/print-jobs')->assertOk();
});
