<?php

namespace App\Modules\Inventory\Filament\Resources\StockTakes\Pages;

use App\Modules\Inventory\Filament\Resources\StockTakes\StockTakeResource;
use Filament\Resources\Pages\ListRecords;

class ListStockTakes extends ListRecords
{
    protected static string $resource = StockTakeResource::class;

    protected function getHeaderActions(): array
    {
        return [StockTakeResource::startAction()];
    }
}
