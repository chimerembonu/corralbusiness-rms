<?php

namespace App\Modules\Orders\Filament\Resources\DiningTables\Pages;

use App\Modules\Orders\Filament\Resources\DiningTables\DiningTableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiningTables extends ListRecords
{
    protected static string $resource = DiningTableResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
