<?php

namespace App\Modules\Payments\Filament\Resources\CashierSessions\Pages;

use App\Modules\Payments\Filament\Resources\CashierSessions\CashierSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListCashierSessions extends ListRecords
{
    protected static string $resource = CashierSessionResource::class;
}
