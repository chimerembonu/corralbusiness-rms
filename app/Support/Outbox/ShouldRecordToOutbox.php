<?php

namespace App\Support\Outbox;

/**
 * Implement this on a business event (OrderCreated, OrderPaid…) and it is
 * appended to the outbox table automatically for cloud sync (Phase 6).
 */
interface ShouldRecordToOutbox
{
    /** e.g. "orders.created" */
    public function outboxEventType(): string;

    /** @return array<string, mixed> JSON-safe data describing what happened. */
    public function outboxPayload(): array;

    public function outboxTenantId(): ?string;

    public function outboxBranchId(): ?string;
}
