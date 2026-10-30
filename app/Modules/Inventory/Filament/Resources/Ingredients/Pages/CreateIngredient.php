<?php

namespace App\Modules\Inventory\Filament\Resources\Ingredients\Pages;

use App\Modules\Inventory\Filament\Resources\Ingredients\IngredientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIngredient extends CreateRecord
{
    protected static string $resource = IngredientResource::class;
}
