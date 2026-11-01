<?php

namespace App\Modules\Payments\Events;

use App\Support\Models\BusinessModel;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

/** Base for cash-management events: the subject row plus who did it, written to the outbox. */
abstract class CashEvent implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly BusinessModel $subject, public readonly ?string $byUserId = null) {}

    abstract public function outboxEventType(): string;

    public function outboxPayload(): array
    {
        return ['id' => $this->subject->getKey(), 'by_user_id' => $this->byUserId] + $this->extraPayload();
    }

    /** @return array<string, mixed> */
    protected function extraPayload(): array
    {
        return [];
    }

    public function outboxTenantId(): ?string
    {
        return $this->subject->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->subject->branch_id;
    }
}
