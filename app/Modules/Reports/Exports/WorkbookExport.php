<?php

namespace App\Modules\Reports\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Several TableExport sheets in one file. */
class WorkbookExport implements Export, WithMultipleSheets
{
    use Exportable;

    /** @param  array<int, TableExport>  $sheets */
    public function __construct(private readonly array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }
}
