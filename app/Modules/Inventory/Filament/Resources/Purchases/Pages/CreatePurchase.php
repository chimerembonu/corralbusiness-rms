<?php

namespace App\Modules\Inventory\Filament\Resources\Purchases\Pages;

use App\Modules\Inventory\Filament\Resources\Purchases\PurchaseResource;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(PurchaseService::class)->create(
            $data['supplier_id'] ? Supplier::query()->find($data['supplier_id']) : null,
            array_map(fn (array $l) => ['ingredient_id' => $l['ingredient_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost'] ?? null], array_values($data['lines'] ?? [])),
            auth()->user(),
            $data['notes'] ?? null,
            $data['expected_on'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseResource::getUrl('index');
    }
}
