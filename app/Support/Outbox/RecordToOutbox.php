<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\TenantContext;

/** Listener: writes any ShouldRecordToOutbox event into the outbox table. */
class RecordToOutbox
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(ShouldRecordToOutbox $event): void
    {
        OutboxEvent::create([
            'tenant_id' => $event->outboxTenantId() ?? $this->context->tenantId(),
            'branch_id' => $event->outboxBranchId() ?? $this->context->branchId(),
            'event_type' => $event->outboxEventType(),
            'payload' => $event->outboxPayload(),
            'created_at' => now(),
        ]);
    }
}
