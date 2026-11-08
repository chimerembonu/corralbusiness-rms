<?php

namespace App\Modules\Inventory\Filament\Resources\ExtraRecipes\Pages;

use App\Modules\Inventory\Filament\Resources\ExtraRecipes\ExtraRecipeResource;
use Filament\Resources\Pages\ListRecords;

class ListExtraRecipes extends ListRecords
{
    protected static string $resource = ExtraRecipeResource::class;

    public function getSubheading(): ?string
    {
        return __('inventory::inventory.recipes.extras_help');
    }
}
