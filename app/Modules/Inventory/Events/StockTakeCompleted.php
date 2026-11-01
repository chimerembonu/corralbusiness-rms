<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\StockTake;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

class StockTakeCompleted implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly StockTake $stockTake, public readonly ?string $byUserId = null) {}

    public function outboxEventType(): string
    {
        return 'inventory.stock_take_completed';
    }

    public function outboxPayload(): array
    {
        return ['stock_take_id' => $this->stockTake->id, 'number' => $this->stockTake->number, 'variance_value' => $this->stockTake->variance_value, 'by_user_id' => $this->byUserId];
    }

    public function outboxTenantId(): ?string
    {
        return $this->stockTake->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->stockTake->branch_id;
    }
}
