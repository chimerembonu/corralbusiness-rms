<?php

namespace App\Modules\Orders\Filament\Resources\DiningTables\Pages;

use App\Modules\Orders\Filament\Resources\DiningTables\DiningTableResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDiningTable extends CreateRecord
{
    protected static string $resource = DiningTableResource::class;
}
