<?php

namespace App\Modules\Pos\Filament\Resources\Tills\Pages;

use App\Modules\Pos\Filament\Resources\Tills\TillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTills extends ListRecords
{
    protected static string $resource = TillResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
