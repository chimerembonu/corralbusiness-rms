<?php

namespace App\Modules\Orders\Filament\Resources\PromoCodes\Pages;

use App\Modules\Orders\Filament\Resources\PromoCodes\PromoCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromoCodes extends ListRecords
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
