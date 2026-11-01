<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\StockMovement;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

class StockMoved implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly StockMovement $movement) {}

    public function outboxEventType(): string
    {
        return 'inventory.stock_moved';
    }

    public function outboxPayload(): array
    {
        return [
            'movement_id' => $this->movement->id,
            'ingredient_id' => $this->movement->ingredient_id,
            'item_id' => $this->movement->item_id,
            'type' => $this->movement->type->value,
            'quantity' => (float) $this->movement->quantity,
            'on_hand_after' => (float) $this->movement->on_hand_after,
            'reason' => $this->movement->reason,
            'user_id' => $this->movement->user_id,
        ];
    }

    public function outboxTenantId(): ?string
    {
        return $this->movement->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->movement->branch_id;
    }
}
