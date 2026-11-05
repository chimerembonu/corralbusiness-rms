<?php

namespace App\Modules\Inventory\Filament\Resources\ExtraRecipes\Pages;

use App\Modules\Inventory\Filament\Resources\ExtraRecipes\ExtraRecipeResource;
use Filament\Resources\Pages\EditRecord;

/** Only the extra's recipe lines are saved here; the extra itself is edited on the Menu pages. */
class EditExtraRecipe extends EditRecord
{
    protected static string $resource = ExtraRecipeResource::class;

    public function getTitle(): string
    {
        return __('inventory::inventory.recipes.extra').': '.$this->record->name;
    }

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
