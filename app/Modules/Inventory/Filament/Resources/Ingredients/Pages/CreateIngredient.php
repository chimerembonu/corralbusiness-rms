<?php

namespace App\Modules\Inventory\Filament\Resources\Ingredients\Pages;

use App\Modules\Inventory\Filament\Resources\Ingredients\IngredientResource;
use App\Modules\Inventory\Services\CostService;
use App\Modules\Inventory\Services\StockService;
use Filament\Resources\Pages\CreateRecord;

class CreateIngredient extends CreateRecord
{
    protected static string $resource = IngredientResource::class;

    protected function afterCreate(): void
    {
        app(StockService::class)->recordOpening($this->record, auth()->user());
        app(CostService::class)->recordOpening($this->record, auth()->user()); // the start of its price history (E5.2)
    }
}
