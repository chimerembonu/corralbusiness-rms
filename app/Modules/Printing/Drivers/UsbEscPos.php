<?php

namespace App\Modules\Printing\Drivers;

use App\Modules\Printing\Contracts\PrinterDriver;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Models\Printer;

/** USB via a CUPS raw queue – implemented in Phase 1.5. */
class UsbEscPos implements PrinterDriver
{
    public function print(Printer $printer, PrintDocument $document): void
    {
        throw new PrintFailedException(__('printing::printing.errors.usb_later'));
    }

    public function completesImmediately(): bool
    {
        return true;
    }
}
