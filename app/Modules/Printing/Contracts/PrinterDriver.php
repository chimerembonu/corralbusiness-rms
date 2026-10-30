<?php

namespace App\Modules\Printing\Contracts;

use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Models\Printer;

/**
 * Hardware abstraction (CLAUDE.md §3.8). Each driver knows how to get a
 * document onto one kind of printer. Throw PrintFailedException on any problem.
 */
interface PrinterDriver
{
    /** Push the document to the printer now. */
    public function print(Printer $printer, PrintDocument $document): void;

    /** true when the job is finished by this call; false when something else (the browser) completes it later. */
    public function completesImmediately(): bool;
}
