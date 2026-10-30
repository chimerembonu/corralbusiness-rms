<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Documents\PrintDocument;

/** Turns a PrintDocument into a printable HTML page (browser print fallback). */
class HtmlRenderer
{
    public function render(PrintDocument $document, int $paperWidthMm = 80, bool $autoPrint = false): string
    {
        return view('printing::document', [
            'document' => $document,
            'paperWidth' => $paperWidthMm,
            'autoPrint' => $autoPrint,
        ])->render();
    }
}
