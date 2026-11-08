<?php

use App\Modules\Kitchen\Models\Station;
use App\Modules\Pos\Models\Till;
use App\Modules\Printing\Contracts\PortProber;
use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Enums\PrinterDriverType;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\PrinterDiscovery;
use App\Modules\Printing\Services\PrinterDriverRegistry;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Support\Tenancy\Tenancy;
use Livewire\Features\SupportTesting\Testable;

/*
 * Complete Edition E1.4 — printers in the setup wizard: found on the network and USB, a test page on each,
 * and assigned to tills (receipts) and kitchen stations (tickets).
 */

class SetupFakeProber implements PortProber
{
    public array $asked = [];

    public function __construct(private array $open = [], private ?string $local = '192.168.8.10/24') {}

    public function open(array $hosts, int $port, float $timeoutSeconds = 0.4): array
    {
        $this->asked = [$hosts[0] ?? null, count($hosts), $port];

        return array_values(array_intersect($hosts, $this->open));
    }

    public function localAddress(): ?string
    {
        return $this->local;
    }
}

class SetupFakeSpooler implements RawSpooler
{
    public array $sent = [];

    public function __construct(private array $queues = []) {}

    public function send(string $queue, string $bytes): void
    {
        $this->sent[] = $queue;
    }

    public function queues(): array
    {
        return array_map(fn ($q) => ['name' => $q, 'status' => 'is idle.'], $this->queues);
    }
}

/** A network printer that "prints" only at 192.168.8.50 and remembers what it got. */
class SetupFakeNetworkDriver implements PrinterDriver
{
    public static array $printed = [];

    public function print(Printer $printer, PrintDocument $document): void
    {
        if ($printer->address !== '192.168.8.50') {
            throw new PrintFailedException("Cannot reach printer at {$printer->address}:9100 (Connection refused).");
        }
        self::$printed[] = [$printer->address, $document->toArray()];
    }

    public function completesImmediately(): bool
    {
        return true;
    }
}

beforeEach(function () {
    SetupFakeNetworkDriver::$printed = [];
    app(PrinterDriverRegistry::class)->register(PrinterDriverType::Network, SetupFakeNetworkDriver::class);
    $this->prober = new SetupFakeProber(['192.168.8.50', '192.168.8.51']);
    $this->spooler = new SetupFakeSpooler(['RMS_USB_1', 'Office_MFP__Fax']);
    $this->app->instance(PortProber::class, $this->prober);
    $this->app->instance(RawSpooler::class, $this->spooler);
});

/** The wizard opened at the printers screen, with two tills and two kitchen stations. */
function printersScreen(): Testable
{
    return walkSetup(['tills' => ['Front counter', 'Bar till'], 'stations' => ['Kitchen', 'Bar']])
        ->call('back')->call('back')->call('back')->assertSet('step', 'printers');
}

function printerKey(Testable $wizard, string $address): string
{
    return (string) collect($wizard->get('printers'))->search(fn ($p) => $p['address'] === $address);
}

it('finds network and USB printers, prints a test page at once, and sets them up for tills and stations', function () {
    $wizard = printersScreen()
        ->call('searchPrinters')->assertHasNoErrors()
        ->assertSet('scanNetwork', '192.168.8.10/24')
        ->assertSee('Network printers on 192.168.8.10/24')->assertSee('192.168.8.50')->assertSee('192.168.8.51')->assertSee('RMS_USB_1')
        ->assertDontSee('Office_MFP__Fax'); // a fax queue can never print a receipt
    expect($this->prober->asked)->toBe(['192.168.8.1', 254, 9100]);

    // Use the two that work: a network receipt printer and a USB kitchen printer.
    $wizard->call('addPrinter', 'network', '192.168.8.50')->call('addPrinter', 'network', '192.168.8.50') // twice = once
        ->call('addPrinter', 'usb', 'RMS_USB_1')->call('addPrinter', 'network', '192.168.8.51')
        ->assertCount('printers', 3)->assertSee('Added');
    $front = printerKey($wizard, '192.168.8.50');
    $usb = printerKey($wizard, 'RMS_USB_1');
    $dead = printerKey($wizard, '192.168.8.51');

    // Test pages go out straight away (no queue) and the screen says whether it worked.
    $wizard->set("printers.{$front}.name", 'Front receipt')->call('testPrinter', $front)
        ->assertSee('Test page sent to Front receipt')
        ->call('testPrinter', $usb)->assertSee('Test page sent to Printer 2')
        ->call('testPrinter', $dead)->assertSee('Cannot reach printer at 192.168.8.51');
    expect(SetupFakeNetworkDriver::$printed)->toHaveCount(1)
        ->and(SetupFakeNetworkDriver::$printed[0][1]['title'])->toBe('Printer test page')
        ->and($this->spooler->sent)->toBe(['RMS_USB_1'])
        ->and(PrintJob::query()->withoutGlobalScopes()->count())->toBe(0);

    // The one that did not print is removed; the others are assigned.
    $wizard->call('removePrinter', $dead)->assertCount('printers', 2)
        ->set("printers.{$front}.tills", ['0', '1'])->set("printers.{$front}.drawer", true)
        ->set("printers.{$usb}.name", 'Kitchen printer')->set("printers.{$usb}.paper_width", '58')->set("printers.{$usb}.stations", ['0', '1'])
        ->call('next')->assertHasNoErrors()->assertSet('step', 'data')
        ->call('next')->call('next')->assertSet('step', 'review')
        ->assertSee('Kitchen, Bar')->assertSee('Front receipt (192.168.8.50): Front counter, Bar till')
        ->call('finish')->assertHasNoErrors()->assertSet('step', 'done');

    Tenancy::runAs(Tenant::query()->sole(), Branch::query()->withoutGlobalScopes()->sole(), function () {
        $kitchen = Station::query()->where('name', 'Kitchen')->sole();
        expect($kitchen->is_default)->toBeTrue()->and($kitchen->code)->toBe('KITCHEN')
            ->and(Station::query()->where('name', 'Bar')->sole()->is_default)->toBeFalse();

        $receipt = Printer::query()->where('name', 'Front receipt')->sole();
        expect($receipt->driver)->toBe(PrinterDriverType::Network)->and($receipt->address)->toBe('192.168.8.50')
            ->and($receipt->prints_receipts)->toBeTrue()->and($receipt->prints_kitchen_tickets)->toBeFalse()
            ->and($receipt->has_cash_drawer)->toBeTrue();

        $kitchenPrinter = Printer::query()->where('name', 'Kitchen printer')->sole();
        expect($kitchenPrinter->driver)->toBe(PrinterDriverType::Usb)->and($kitchenPrinter->paper_width)->toBe(58)
            ->and($kitchenPrinter->prints_kitchen_tickets)->toBeTrue()->and($kitchenPrinter->prints_receipts)->toBeFalse()
            ->and($kitchenPrinter->station_ids)->toBeNull(); // every station ticked = all stations, also ones added later

        expect(Till::query()->pluck('receipt_printer_id', 'name')->all())
            ->toBe(['Front counter' => $receipt->id, 'Bar till' => $receipt->id]);
    });
});

it('sends only the ticked stations to a printer', function () {
    $wizard = printersScreen()->call('addPrinter', 'network', '192.168.8.50');
    $key = printerKey($wizard, '192.168.8.50');
    $wizard->set("printers.{$key}.stations", ['1'])->call('next')->assertHasNoErrors()
        ->call('next')->call('next')->call('finish')->assertSet('step', 'done');

    Tenancy::runAs(Tenant::query()->sole(), Branch::query()->withoutGlobalScopes()->sole(), function () {
        expect(Printer::query()->sole()->station_ids)->toBe([Station::query()->where('name', 'Bar')->sole()->id])
            ->and(Till::query()->whereNotNull('receipt_printer_id')->count())->toBe(0);
    });
});

it('stops printer mistakes before they reach the restaurant', function () {
    $wizard = printersScreen()->call('addPrinter')->call('addPrinter', 'network', '192.168.8.50');
    [$hand, $found] = array_keys($wizard->get('printers'));

    // A printer that does nothing; a till on two printers; an address on the internet.
    $wizard->set("printers.{$hand}.address", '8.8.8.8')->set("printers.{$hand}.tills", ['0'])
        ->set("printers.{$found}.tills", ['0'])
        ->call('next')->assertSet('step', 'printers')
        ->assertHasErrors(["printers.{$hand}.address", "printers.{$found}.tills"])
        ->assertSee('"8.8.8.8" is not a printer address')->assertSee('Front counter already prints its receipts on another printer');

    $wizard->set("printers.{$hand}.address", '192.168.8.60:9101')->set("printers.{$hand}.tills", [])
        ->call('next')->assertHasErrors(["printers.{$hand}.tills"])->assertSee('Tick at least one till or kitchen station')
        ->call('testPrinter', $hand)->assertSee('Cannot reach printer at 192.168.8.60:9101');

    // Removing a till or station on the screen before also takes it off the printers.
    $wizard->set("printers.{$hand}.tills", ['1'])->set("printers.{$hand}.stations", ['1'])
        ->call('back')->assertSet('step', 'tills')->call('removeTill')->call('removeStation')
        ->assertSet("printers.{$hand}.tills", [])->assertSet("printers.{$hand}.stations", []);

    // Only restaurant (private) networks can be searched, and not too often.
    $wizard->call('next')->assertSet('step', 'printers')
        ->set('scanNetwork', '8.8.8.0/24')->call('searchPrinters')->assertSee('is not a restaurant network')
        ->set('scanNetwork', '10.0.0.0/8')->call('searchPrinters')->assertSee('is not a restaurant network');
    foreach (range(1, 4) as $_) {
        $wizard->set('scanNetwork', '')->call('searchPrinters');
    }
    $wizard->call('searchPrinters')->assertSee('Please wait');

    expect(Tenant::query()->count())->toBe(0)->and(SetupFakeNetworkDriver::$printed)->toBe([]);
});

it('lets the wizard skip printers entirely', function () {
    walkSetup()->call('finish')->assertSet('step', 'done');

    Tenancy::runAs(Tenant::query()->sole(), Branch::query()->withoutGlobalScopes()->sole(), function () {
        expect(Printer::query()->count())->toBe(0)
            ->and(Station::query()->pluck('name')->all())->toBe(['Kitchen']);
    });
});

it('searches the network the installer wrote down, or this computer\'s own network', function () {
    $discovery = app(PrinterDiscovery::class);
    expect($discovery->subnet())->toBe('192.168.8.10/24');

    config(['rms.printers.subnet' => '192.168.1.0/24']);
    expect($discovery->subnet())->toBe('192.168.1.0/24');

    // Inside Docker the app sees only its own wide network: narrowed rather than scanning 65,000 addresses.
    config(['rms.printers.subnet' => null]);
    $this->app->instance(PortProber::class, new SetupFakeProber([], '172.18.0.5/16'));
    expect(app(PrinterDiscovery::class)->subnet())->toBe('172.18.0.5/24');

    expect(PrinterDiscovery::validAddress(PrinterDriverType::Network, '192.168.8.50'))->toBeTrue()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Network, '10.1.2.3:9100'))->toBeTrue()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Network, '41.66.1.1'))->toBeFalse()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Network, '192.168.8.50:99999'))->toBeFalse()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Network, '192.168.8.50:9105'))->toBeTrue()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Network, '192.168.8.1:22'))->toBeFalse()   // not a printer port (E9.6 review L3)
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Network, '192.168.8.1:3306'))->toBeFalse()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Usb, 'RMS_USB_1'))->toBeTrue()
        ->and(PrinterDiscovery::validAddress(PrinterDriverType::Usb, 'a; rm -rf /'))->toBeFalse();
});
