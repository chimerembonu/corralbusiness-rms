<?php

namespace App\Modules\Payments\Filament\Resources\DayCloses\Pages;

use App\Modules\Payments\Filament\Resources\DayCloses\DayCloseResource;
use Filament\Resources\Pages\ListRecords;

class ListDayCloses extends ListRecords
{
    protected static string $resource = DayCloseResource::class;
}
