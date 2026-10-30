<?php

namespace App\Modules\Printing\Filament\Resources\PrintJobs\Pages;

use App\Modules\Printing\Filament\Resources\PrintJobs\PrintJobResource;
use Filament\Resources\Pages\ListRecords;

class ListPrintJobs extends ListRecords
{
    protected static string $resource = PrintJobResource::class;
}
