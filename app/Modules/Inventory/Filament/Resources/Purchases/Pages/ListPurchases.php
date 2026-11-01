<?php

namespace App\Modules\Inventory\Filament\Resources\Purchases\Pages;

use App\Modules\Inventory\Filament\Resources\Purchases\PurchaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
