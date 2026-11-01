<?php

namespace App\Modules\Pos\Filament\Resources\Tills\Pages;

use App\Modules\Pos\Filament\Resources\Tills\TillResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTill extends CreateRecord
{
    protected static string $resource = TillResource::class;
}
