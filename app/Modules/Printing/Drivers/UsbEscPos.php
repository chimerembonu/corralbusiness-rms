<?php

namespace App\Modules\Printing\Drivers;

use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\EscPosRenderer;

/**
 * USB (or serial/parallel) ESC/POS printer attached to the store server, reached through the
 * computer's own print queue. Address = the queue name (Mac/Linux: a CUPS "raw" queue;
 * Windows: a shared printer name or COM port). Works with Xprinter, Epson TM and most others.
 */
class UsbEscPos implements PrinterDriver
{
    public function __construct(private readonly EscPosRenderer $renderer, private readonly RawSpooler $spooler) {}

    public function print(Printer $printer, PrintDocument $document): void
    {
        $queue = trim((string) $printer->address);
        if ($queue === '') {
            throw new PrintFailedException(__('printing::printing.errors.bad_queue', ['queue' => '']));
        }

        $this->spooler->send($queue, $this->renderer->render($document, $printer->paper_width));
    }

    public function completesImmediately(): bool
    {
        return true;
    }
}
