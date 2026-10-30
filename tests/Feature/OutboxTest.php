<?php

use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\ShouldRecordToOutbox;
use App\Support\Tenancy\Tenancy;

class FakeOrderPaid implements ShouldRecordToOutbox
{
    public function __construct(public string $orderId, public int $total) {}

    public function outboxEventType(): string
    {
        return 'orders.paid';
    }

    public function outboxPayload(): array
    {
        return ['order_id' => $this->orderId, 'total' => $this->total];
    }

    public function outboxTenantId(): ?string
    {
        return null;
    }

    public function outboxBranchId(): ?string
    {
        return null;
    }
}

it('appends business events to the outbox', function () {
    $branch = provisionTenant();

    event(new FakeOrderPaid('abc', 12000));

    $row = OutboxEvent::sole();
    expect($row->event_type)->toBe('orders.paid')
        ->and($row->payload)->toBe(['order_id' => 'abc', 'total' => 12000])
        ->and($row->tenant_id)->toBe($branch->tenant_id)
        ->and($row->branch_id)->toBe($branch->id)
        ->and($row->synced_at)->toBeNull()
        ->and(OutboxEvent::pending()->count())->toBe(1);

    Tenancy::forget();
});
