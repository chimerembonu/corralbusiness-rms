<?php

namespace App\Modules\Pos\Filament\Resources\Tills\Pages;

use App\Modules\Pos\Filament\Resources\Tills\TillResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTill extends EditRecord
{
    protected static string $resource = TillResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
