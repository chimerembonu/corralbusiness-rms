<?php

namespace App\Modules\Printing\Console;

use App\Modules\Printing\Contracts\RawSpooler;
use Illuminate\Console\Command;

/** `php artisan rms:printers:queues` — the print queues on this computer, for USB printer setup. */
class ListPrintQueues extends Command
{
    protected $signature = 'rms:printers:queues';

    protected $description = 'List the print queues on this computer (use a queue name as the address of a USB printer)';

    public function handle(RawSpooler $spooler): int
    {
        $queues = $spooler->queues();

        if ($queues === []) {
            $this->components->warn('No print queues found on this computer.');
            $this->line('Mac:    lpadmin -p RECEIPT -E -v "$(lpinfo -v | grep -i usb | head -1 | awk \'{print $2}\')" -m raw');
            $this->line('Ubuntu: sudo lpadmin -p RECEIPT -E -v "$(lpinfo -v | grep -i usb | head -1 | awk \'{print $2}\')" -m raw');
            $this->line('Then run this command again; "RECEIPT" is the queue name to type into Back office → Printers → Address.');

            return self::SUCCESS;
        }

        $this->table(['Queue name (use as printer address)', 'Status'], array_map(fn ($q) => [$q['name'], $q['status']], $queues));
        $this->line('In Back office → Printers, add a printer with connection "USB thermal printer" and the queue name as the Address.');

        return self::SUCCESS;
    }
}
