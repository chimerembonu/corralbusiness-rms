<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Contracts\PortProber;
use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Documents\TestPageDocument;
use App\Modules\Printing\Enums\PrinterDriverType;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Models\Printer;
use Throwable;

/**
 * Finds printers (Complete Edition E1.4): network thermal printers answer on port 9100 of the restaurant
 * network; USB printers appear as print queues on the store computer. Also prints a test page straight
 * away (not queued) so whoever is setting up sees at once whether a printer works.
 */
class PrinterDiscovery
{
    public const PORT = 9100;

    public function __construct(
        private readonly PortProber $prober,
        private readonly RawSpooler $spooler,
        private readonly PrinterDriverRegistry $drivers,
    ) {}

    /** The restaurant network to search: RMS_PRINTER_SUBNET, else this computer's own network (at most a /22). */
    public function subnet(): ?string
    {
        $configured = trim((string) config('rms.printers.subnet'));
        if ($configured !== '') {
            return $configured;
        }
        $local = $this->prober->localAddress();
        if (! $local || ! preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $local, $m)) {
            return null;
        }

        return (int) $m[2] < 22 ? $m[1].'/24' : $local;
    }

    /**
     * Network printers on a restaurant (private) network.
     *
     * @return array{subnet: string, addresses: array<int, string>}
     *
     * @throws PrintFailedException when the network is unknown, not private, or too big
     */
    public function scanNetwork(?string $subnet = null): array
    {
        $subnet = trim((string) ($subnet ?: $this->subnet()));
        if ($subnet === '') {
            throw new PrintFailedException(__('printing::printing.discovery.no_network'));
        }
        $hosts = self::hosts($subnet);
        if ($hosts === [] || ! self::isPrivate($hosts[0])) {
            throw new PrintFailedException(__('printing::printing.discovery.bad_network', ['network' => $subnet]));
        }

        return ['subnet' => $subnet, 'addresses' => $this->prober->open($hosts, self::PORT)];
    }

    /** @return array<int, array{name: string, status: string}> print queues on this computer (USB printers) */
    public function usbQueues(): array
    {
        try {
            // Fax queues share a printer's name on many computers and can never print a receipt.
            return array_values(array_filter($this->spooler->queues(), fn (array $q) => stripos($q['name'], 'fax') === false));
        } catch (Throwable) {
            return []; // no print system on this computer: simply no USB printers
        }
    }

    /**
     * Prints a test page now. $details: driver (network|usb), address, name?, paper_width?
     *
     * @throws PrintFailedException
     */
    public function testPrint(array $details): void
    {
        $driver = PrinterDriverType::tryFrom((string) ($details['driver'] ?? ''));
        $address = trim((string) ($details['address'] ?? ''));
        if (! $driver || ! self::validAddress($driver, $address)) {
            throw new PrintFailedException(__('printing::printing.discovery.bad_address', ['address' => $address]));
        }
        $printer = new Printer([
            // Printed on the page: plain text, short (E9.6 review L3).
            'name' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($details['name'] ?? ''))), 0, 40) ?: $address,
            'driver' => $driver,
            'address' => $address,
            'paper_width' => (int) ($details['paper_width'] ?? 80) === 58 ? 58 : 80,
        ]);

        try {
            $this->drivers->for($driver)->print($printer, TestPageDocument::make($printer));
        } catch (PrintFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PrintFailedException($e->getMessage(), 0, $e);
        }
    }

    /** Receipt printers listen on 9100 (a few boxes with several printers use 9101–9109). */
    public const MIN_PORT = 9100;

    public const MAX_PORT = 9109;

    /**
     * A network printer is a private-network IPv4 address with an optional printer port; a USB printer is a
     * print queue name. Nothing else is accepted, so the server cannot be pointed at the internet, or at other
     * services inside the restaurant (E9.6 review L3).
     */
    public static function validAddress(PrinterDriverType $driver, string $address): bool
    {
        $address = trim($address);

        return match ($driver) {
            PrinterDriverType::Network => (bool) preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::(\d{1,5}))?$/', $address, $m)
                && self::isPrivate($m[1])
                && (! isset($m[2]) || ((int) $m[2] >= self::MIN_PORT && (int) $m[2] <= self::MAX_PORT)),
            PrinterDriverType::Usb => (bool) preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $address),
            PrinterDriverType::Browser => $address === '',
        };
    }

    /** 10.x, 172.16–31.x and 192.168.x: the address ranges routers give out inside a building. */
    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false;
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
