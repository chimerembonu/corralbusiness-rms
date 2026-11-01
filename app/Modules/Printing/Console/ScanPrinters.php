<?php

namespace App\Modules\Printing\Console;

use App\Modules\Printing\Contracts\PortProber;
use App\Modules\Printing\Models\Printer;
use Illuminate\Console\Command;

/** `php artisan rms:printers:scan` — finds network thermal printers (port 9100) on the restaurant network. */
class ScanPrinters extends Command
{
    protected $signature = 'rms:printers:scan {--subnet= : Network to scan, e.g. 192.168.8.0/24 (default: this computer\'s network)} {--port=9100}';

    protected $description = 'Find network receipt/kitchen printers on the restaurant network';

    public function handle(PortProber $prober): int
    {
        $subnet = $this->option('subnet') ?: $prober->localAddress();
        if (! $subnet) {
            $this->components->error('Could not work out this computer\'s network. Run again with --subnet=192.168.8.0/24 (your router\'s range).');

            return self::FAILURE;
        }

        $hosts = self::hosts($subnet);
        if ($hosts === []) {
            $this->components->error("\"{$subnet}\" is not a valid network. Example: 192.168.8.0/24");

            return self::FAILURE;
        }

        $port = (int) $this->option('port');
        $this->components->info(sprintf('Scanning %s (%d addresses) for printers on port %d…', $subnet, count($hosts), $port));
        $found = $prober->open($hosts, $port);

        if ($found === []) {
            $this->components->warn('No printers answered. Check the printer is on, plugged into the same router, and print its self-test page (hold the feed button while switching on) to read its address.');

            return self::SUCCESS;
        }

        $known = Printer::query()->withoutGlobalScopes()->get()->keyBy(fn (Printer $p) => preg_replace('/:\d+$/', '', (string) $p->address));
        $this->table(['Printer address', 'Already set up as'], array_map(fn ($ip) => [$ip, isset($known[$ip]) ? $known[$ip]->name : '— (add it in Back office → Printers, connection "Network")'], $found));

        return self::SUCCESS;
    }

    /**
     * Every usable host address in a CIDR block (network and broadcast left out). Blocks bigger than /22 are refused.
     *
     * @return array<int, string>
     */
    public static function hosts(string $cidr): array
    {
        if (! preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', trim($cidr), $m)) {
            return [];
        }
        $ip = ip2long($m[1]);
        $bits = (int) $m[2];
        if ($ip === false || $bits < 22 || $bits > 30) {
            return [];
        }
        $size = 2 ** (32 - $bits);
        $network = $ip & (-1 << (32 - $bits));
        $hosts = [];
        for ($i = 1; $i < $size - 1; $i++) {
            $hosts[] = long2ip($network + $i);
        }

        return $hosts;
    }
}
