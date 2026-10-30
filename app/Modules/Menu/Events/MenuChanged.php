<?php

namespace App\Modules\Menu\Events;

use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired after any menu edit so POS screens refresh their cached menu. */
class MenuChanged implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly string $tenantId, public readonly string $what, public readonly ?string $id = null) {}

    public function outboxEventType(): string
    {
        return 'menu.changed';
    }

    public function outboxPayload(): array
    {
        return ['what' => $this->what, 'id' => $this->id];
    }

    public function outboxTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function outboxBranchId(): ?string
    {
        return null;
    }
}
