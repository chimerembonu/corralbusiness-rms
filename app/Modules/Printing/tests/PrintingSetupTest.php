<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Documents\ReceiptDocument;
use App\Modules\Printing\Documents\ReceiptSettings;
use App\Modules\Printing\Drivers\UsbEscPos;
use App\Modules\Printing\Enums\PrintJobType;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\EscPosRenderer;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Settings\Filament\Pages\ReceiptSettingsPage;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** Records what was "printed" instead of talking to a real queue. */
class FakeSpooler implements RawSpooler
{
    /** @var array<int, array{queue: string, bytes: string}> */
    public array $sent = [];

    public function send(string $queue, string $bytes): void
    {
        if ($queue === 'BROKEN') {
            throw new PrintFailedException('lp: The printer or class does not exist.');
        }
        $this->sent[] = ['queue' => $queue, 'bytes' => $bytes];
    }

    public function queues(): array
    {
        return [['name' => 'RECEIPT', 'status' => 'is idle.'], ['name' => 'KITCHEN', 'status' => 'is idle.']];
    }
}

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1001')->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->spooler = new FakeSpooler;
    app()->instance(RawSpooler::class, $this->spooler);
});

it('sends ESC/POS bytes to a USB print queue and reports a missing queue clearly', function () {
    $printer = Printer::create(['name' => 'Counter USB', 'driver' => 'usb', 'address' => 'RECEIPT', 'paper_width' => 58]);
    $driver = app(UsbEscPos::class);

    $driver->print($printer, PrintDocument::make()->text('Hello')->cut());
    expect($this->spooler->sent)->toHaveCount(1)
        ->and($this->spooler->sent[0]['queue'])->toBe('RECEIPT')
        ->and($this->spooler->sent[0]['bytes'])->toContain('Hello')->toStartWith("\x1b@");   // ESC @ = initialise

    $printer->update(['address' => 'BROKEN']);
    expect(fn () => $driver->print($printer, PrintDocument::make()->text('x')))->toThrow(PrintFailedException::class, 'does not exist');
    $printer->update(['address' => '']);
    expect(fn () => $driver->print($printer, PrintDocument::make()->text('x')))->toThrow(PrintFailedException::class, 'queue name');

    $this->artisan('rms:printers:queues')->expectsOutputToContain('RECEIPT')->assertSuccessful();
});

it('pops the cash drawer on a cash payment and on the no-sale button, but not for Mobile Money', function () {
    $printer = Printer::create(['name' => 'Counter', 'driver' => 'usb', 'address' => 'RECEIPT', 'prints_receipts' => true, 'has_cash_drawer' => true]);
    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($order, $this->menu['coke']);

    app(PaymentService::class)->payMobileMoney($order, MomoProvider::Mtn, Money::fromDecimal('2'), 'MP1', $this->cashier);
    expect(PrintJob::query()->where('type', PrintJobType::Drawer->value)->count())->toBe(0);

    app(PaymentService::class)->payCash($order->fresh(), Money::fromDecimal('10'), $this->cashier);
    expect(PrintJob::query()->where('type', PrintJobType::Drawer->value)->count())->toBe(1)
        ->and(PrintJob::query()->where('type', PrintJobType::Receipt->value)->count())->toBe(1);

    // The queued drawer job really sends the pulse bytes.
    $drawerJob = PrintJob::query()->where('type', PrintJobType::Drawer->value)->first();
    expect(app(EscPosRenderer::class)->render($drawerJob->documentObject()))->toContain("\x1bp");   // ESC p = drawer kick

    $this->actingAs($this->cashier)->post(route('pos.drawer'))->assertRedirect()->assertSessionHas('status');
    expect(PrintJob::query()->where('type', PrintJobType::Drawer->value)->count())->toBe(2)
        ->and(Activity::query()->where('log_name', 'cash_drawer')->where('description', 'drawer_opened')->count())->toBe(1);

    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->actingAs($waiter)->post(route('pos.drawer'))->assertForbidden();
});

it('remembers which till a device is and prints receipts on that till\'s printer', function () {
    $counterPrinter = Printer::create(['name' => 'Counter', 'driver' => 'usb', 'address' => 'RECEIPT', 'prints_receipts' => true]);
    $barPrinter = Printer::create(['name' => 'Bar', 'driver' => 'usb', 'address' => 'BAR', 'prints_receipts' => true]);
    $till = Till::create(['name' => 'Till 5 – Bar', 'code' => 'T5', 'receipt_printer_id' => $barPrinter->id]);
    Till::create(['name' => 'Till 1', 'code' => 'T1']);

    // Sign-in screen offers the tills; choosing one sets the cookie.
    Livewire::test(PinLogin::class)
        ->assertSee('Which till is this device?')->assertSee('Till 5 – Bar')
        ->call('chooseTill', $till->id)->assertSet('tillId', $till->id)->assertSee('This device is Till 5 – Bar');

    // With the cookie, receipts go only to that till's printer.
    $this->withCookie(CurrentTill::COOKIE, $till->id)->actingAs($this->cashier)->get('/pos')->assertOk()->assertSee('Till 5 – Bar');
    $this->withUnencryptedCookie(CurrentTill::COOKIE, $till->id);
    app()->forgetInstance(CurrentTill::class);
    request()->cookies->set(CurrentTill::COOKIE, $till->id);

    expect(app(PrintService::class)->receiptPrinters($this->branch->id)->pluck('id')->all())->toBe([$barPrinter->id]);

    request()->cookies->remove(CurrentTill::COOKIE);
    app()->forgetInstance(CurrentTill::class);
    expect(app(PrintService::class)->receiptPrinters($this->branch->id)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$counterPrinter->id, $barPrinter->id])->sort()->values()->all());
});

it('prints the logo, TIN, header lines and footer from the receipt design, on paper and on screen', function () {
    Storage::fake('public');
    $owner = User::factory()->forBranch($this->branch)->create();
    $owner->assignRole(Role::Owner->value);
    $logo = imagecreatetruecolor(600, 200);
    imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 255, 255));
    imagestring($logo, 5, 10, 10, 'CORRAL', imagecolorallocate($logo, 0, 0, 0));
    ob_start();
    imagepng($logo);
    Storage::disk('public')->put('receipts/logo.png', ob_get_clean());

    // The logo itself is picked with the upload box (a real file in the browser); here it is already stored.
    app(SettingsService::class)->set('receipt.logo_path', 'receipts/logo.png');

    $this->actingAs($owner);
    $this->get('/admin/receipt-settings-page')->assertOk()->assertSee('Receipt design');
    Livewire::test(ReceiptSettingsPage::class)
        ->fillForm(['tax_number' => 'C0001234567', 'header' => "Akwaaba!\nOpen daily 7am - 11pm", 'footer' => 'Medaase!', 'show_server' => false])
        ->call('save')->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    $settings = app(PrintService::class)->receiptSettings($this->branch->id);
    expect($settings->taxNumber)->toBe('C0001234567')->and($settings->headerLines())->toBe(['Akwaaba!', 'Open daily 7am - 11pm'])->and($settings->showServer)->toBeFalse()
        ->and($settings->logoPath)->toBe('receipts/logo.png');

    $this->get(route('receipt.preview'))->assertOk()->assertSee('Sample receipt')->assertSee('TIN: C0001234567')->assertSee('Open daily 7am - 11pm')->assertSee('Medaase!')->assertDontSee('Served by');

    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($order, $this->menu['coke']);
    app(PaymentService::class)->payCash($order, Money::fromDecimal('12'), $this->cashier);

    $settings = new ReceiptSettings(header: 'Akwaaba!', footer: 'Medaase!', taxNumber: 'C0001234567', logoPath: 'receipts/logo.png');
    $doc = ReceiptDocument::fromOrder($order->fresh(), $settings);
    $lines = collect($doc->lines());
    expect($lines->first()['type'])->toBe('image')
        ->and($lines->pluck('text')->filter()->values()->all())->toContain('TIN: C0001234567', 'Akwaaba!', 'Medaase!');

    // Rendering with a real image produces raster bytes (GS v 0) and never throws, even when the file is missing.
    $bytes = app(EscPosRenderer::class)->render($doc, 80);
    expect(strlen($bytes))->toBeGreaterThan(400)->and($bytes)->toContain("\x1dv0");
    $missing = ReceiptDocument::fromOrder($order->fresh(), new ReceiptSettings(logoPath: 'receipts/nope.png'));
    expect(app(EscPosRenderer::class)->render($missing))->toContain('Coca-Cola');
});
