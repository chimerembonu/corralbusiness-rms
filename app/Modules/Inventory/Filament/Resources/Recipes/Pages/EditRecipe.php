<?php

namespace App\Modules\Inventory\Filament\Resources\Recipes\Pages;

use App\Modules\Inventory\Filament\Resources\Recipes\RecipeResource;
use Filament\Resources\Pages\EditRecord;

/** Only the recipe lines are saved here; the dish itself is edited on the Menu pages. */
class EditRecipe extends EditRecord
{
    protected static string $resource = RecipeResource::class;

    public function getTitle(): string
    {
        return __('inventory::inventory.recipes.recipe').': '.$this->record->name;
    }

    /** The dish's own details (price, name…) are not part of this form: only its recipe lines (the repeater). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('inventory::inventory.recipes.saved');
    }
}
