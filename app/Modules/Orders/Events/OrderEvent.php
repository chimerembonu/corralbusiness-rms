<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

/** Base for every order event (CLAUDE.md §3.7). All are written to the outbox. */
abstract class OrderEvent implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly Order $order, public readonly ?string $byUserId = null) {}

    abstract public function outboxEventType(): string;

    /** @return array<string, mixed> */
    protected function extraPayload(): array
    {
        return [];
    }

    public function outboxPayload(): array
    {
        return array_merge([
            'order_id' => $this->order->id,
            'number' => $this->order->number,
            'business_date' => $this->order->business_date?->toDateString(),
            'type' => $this->order->type->value,
            'status' => $this->order->status->value,
            'table_id' => $this->order->table_id,
            'total' => $this->order->total->amount,
            'tax_total' => $this->order->tax_total->amount,
            'by_user_id' => $this->byUserId,
        ], $this->extraPayload());
    }

    public function outboxTenantId(): ?string
    {
        return $this->order->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->order->branch_id;
    }
}
