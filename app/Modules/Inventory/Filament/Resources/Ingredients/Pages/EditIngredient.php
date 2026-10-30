<?php

namespace App\Modules\Inventory\Filament\Resources\Ingredients\Pages;

use App\Modules\Inventory\Filament\Resources\Ingredients\IngredientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIngredient extends EditRecord
{
    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
