<?php

namespace App\Modules\Inventory\Filament\Resources\Purchases\Pages;

use App\Modules\Inventory\Filament\Resources\Purchases\PurchaseResource;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Modules\Inventory\Services\PurchaseService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [PurchaseResource::receiveAction(), PurchaseResource::markOrderedAction(), PurchaseResource::cancelAction()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->record->lines()->get()->map(fn (PurchaseLine $l) => ['ingredient_id' => $l->ingredient_id, 'quantity' => (float) $l->quantity_ordered, 'unit_cost' => $l->unit_cost->amount])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->fill(['supplier_id' => $data['supplier_id'] ?? null, 'expected_on' => $data['expected_on'] ?? null, 'notes' => $data['notes'] ?? null])->save();
        app(PurchaseService::class)->replaceLines($record, array_map(fn (array $l) => ['ingredient_id' => $l['ingredient_id'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost'] ?? null], array_values($data['lines'] ?? [])));

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseResource::getUrl('index');
    }
}
