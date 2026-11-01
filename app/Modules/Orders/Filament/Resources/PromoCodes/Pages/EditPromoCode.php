<?php

namespace App\Modules\Orders\Filament\Resources\PromoCodes\Pages;

use App\Modules\Orders\Filament\Resources\PromoCodes\PromoCodeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromoCode extends EditRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
