<?php

namespace App\Modules\Printing\Filament\Resources\Printers\Pages;

use App\Modules\Printing\Filament\Resources\Printers\PrinterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrinters extends ListRecords
{
    protected static string $resource = PrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
