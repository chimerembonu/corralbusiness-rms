<?php

namespace App\Modules\Menu\Filament\Resources\ModifierGroups\Pages;

use App\Modules\Menu\Filament\Resources\ModifierGroups\ModifierGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditModifierGroup extends EditRecord
{
    protected static string $resource = ModifierGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
