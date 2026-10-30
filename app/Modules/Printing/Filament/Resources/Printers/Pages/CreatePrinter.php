<?php

namespace App\Modules\Printing\Filament\Resources\Printers\Pages;

use App\Modules\Printing\Filament\Resources\Printers\PrinterResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrinter extends CreateRecord
{
    protected static string $resource = PrinterResource::class;
}
