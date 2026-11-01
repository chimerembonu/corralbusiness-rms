<?php

namespace App\Modules\Orders\Filament\Resources\FloorSections\Pages;

use App\Modules\Orders\Filament\Resources\FloorSections\FloorSectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFloorSection extends EditRecord
{
    protected static string $resource = FloorSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
