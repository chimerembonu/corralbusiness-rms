<?php

namespace App\Modules\Orders\Filament\Resources\PromoCodes\Pages;

use App\Modules\Orders\Filament\Resources\PromoCodes\PromoCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;
}
