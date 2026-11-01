<?php

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\Purchase;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

class PurchaseReceived implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly Purchase $purchase, public readonly ?string $byUserId = null) {}

    public function outboxEventType(): string
    {
        return 'inventory.purchase_received';
    }

    public function outboxPayload(): array
    {
        return ['purchase_id' => $this->purchase->id, 'number' => $this->purchase->number, 'supplier_id' => $this->purchase->supplier_id, 'total' => $this->purchase->total->amount, 'by_user_id' => $this->byUserId];
    }

    public function outboxTenantId(): ?string
    {
        return $this->purchase->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->purchase->branch_id;
    }
}
