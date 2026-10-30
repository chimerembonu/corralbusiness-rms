<?php

namespace App\Modules\Printing\Drivers;

use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Models\Printer;

/**
 * Browser printing: nothing is sent from the server. The job waits in
 * "waiting_browser" until a till's browser picks it up and calls window.print()
 * (see BrowserPrintAgent in the POS layout).
 */
class BrowserPrint implements PrinterDriver
{
    public function print(Printer $printer, PrintDocument $document): void
    {
        // Intentionally empty: the browser completes the job.
    }

    public function completesImmediately(): bool
    {
        return false;
    }
}
