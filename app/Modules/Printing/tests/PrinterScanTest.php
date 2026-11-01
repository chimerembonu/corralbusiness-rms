<?php

use App\Modules\Printing\Console\ScanPrinters;
use App\Modules\Printing\Contracts\PortProber;
use App\Modules\Printing\Enums\PrinterDriverType;
use App\Modules\Printing\Models\Printer;

class FakeProber implements PortProber
{
    public array $asked = [];

    public function __construct(private array $open = [], private ?string $local = '192.168.8.10/24') {}

    public function open(array $hosts, int $port, float $timeoutSeconds = 0.4): array
    {
        $this->asked = [count($hosts), $port];

        return array_values(array_intersect($hosts, $this->open));
    }

    public function localAddress(): ?string
    {
        return $this->local;
    }
}

it('lists the host addresses of a network block', function () {
    expect(ScanPrinters::hosts('192.168.8.0/24'))->toHaveCount(254)
        ->and(ScanPrinters::hosts('192.168.8.10/24')[0])->toBe('192.168.8.1')
        ->and(ScanPrinters::hosts('192.168.8.10/24')[253])->toBe('192.168.8.254')
        ->and(ScanPrinters::hosts('10.0.0.0/30'))->toBe(['10.0.0.1', '10.0.0.2'])
        ->and(ScanPrinters::hosts('10.0.0.0/8'))->toBe([])   // too big
        ->and(ScanPrinters::hosts('nonsense'))->toBe([]);
});

it('finds printers on the local network and shows which are already set up', function () {
    provisionTenant();
    Printer::create(['name' => 'Kitchen printer', 'driver' => PrinterDriverType::Network, 'address' => '192.168.8.50:9100', 'paper_width' => 80]);
    $prober = new FakeProber(['192.168.8.50', '192.168.8.51']);
    $this->app->instance(PortProber::class, $prober);

    $this->artisan('rms:printers:scan')
        ->expectsOutputToContain('Scanning 192.168.8.10/24 (254 addresses) for printers on port 9100')
        ->expectsOutputToContain('Kitchen printer')      // same line as 192.168.8.50
        ->expectsOutputToContain('192.168.8.51')
        ->assertExitCode(0);
    expect($prober->asked)->toBe([254, 9100]);

    $this->app->instance(PortProber::class, new FakeProber([], null));
    $this->artisan('rms:printers:scan')->expectsOutputToContain('--subnet=')->assertExitCode(1);
    $this->artisan('rms:printers:scan --subnet=10.1.2.0/24')->expectsOutputToContain('No printers answered')->assertExitCode(0);
});
