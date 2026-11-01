<?php

namespace App\Modules\Orders\Filament\Resources\FloorSections\Pages;

use App\Modules\Orders\Filament\Resources\FloorSections\FloorSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFloorSections extends ListRecords
{
    protected static string $resource = FloorSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
