<?php

namespace App\Modules\Inventory\Filament\Resources\Recipes\Pages;

use App\Modules\Inventory\Filament\Resources\Recipes\RecipeResource;
use Filament\Resources\Pages\ListRecords;

class ListRecipes extends ListRecords
{
    protected static string $resource = RecipeResource::class;

    public function getSubheading(): ?string
    {
        return __('inventory::inventory.recipes.help');
    }
}
