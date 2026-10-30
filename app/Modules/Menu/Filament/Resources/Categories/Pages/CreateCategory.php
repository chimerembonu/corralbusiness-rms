<?php

namespace App\Modules\Menu\Filament\Resources\Categories\Pages;

use App\Modules\Menu\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;
}
