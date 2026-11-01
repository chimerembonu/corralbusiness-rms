<?php

namespace App\Modules\Staff\Events;

use App\Modules\Staff\Models\TimeEntry;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

abstract class TimeEntryEvent implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly TimeEntry $entry) {}

    abstract public function outboxEventType(): string;

    public function outboxPayload(): array
    {
        return [
            'time_entry_id' => $this->entry->id,
            'user_id' => $this->entry->user_id,
            'clocked_in_at' => $this->entry->clocked_in_at->toIso8601String(),
            'clocked_out_at' => $this->entry->clocked_out_at?->toIso8601String(),
            'minutes' => $this->entry->minutes,
            'source' => $this->entry->source->value,
        ];
    }

    public function outboxTenantId(): ?string
    {
        return $this->entry->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->entry->branch_id;
    }
}
