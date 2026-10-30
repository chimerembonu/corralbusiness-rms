<?php

namespace App\Modules\Menu\Events;

use App\Modules\Menu\Models\Item;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired when an item is 86'd (sold out) or put back on sale from the POS or admin. */
class ItemSoldOutChanged implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly Item $item, public readonly bool $soldOut, public readonly ?string $byUserId = null) {}

    public function outboxEventType(): string
    {
        return $this->soldOut ? 'menu.item.sold_out' : 'menu.item.available';
    }

    public function outboxPayload(): array
    {
        return ['item_id' => $this->item->id, 'name' => $this->item->name, 'sold_out' => $this->soldOut, 'by_user_id' => $this->byUserId];
    }

    public function outboxTenantId(): ?string
    {
        return $this->item->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->item->branch_id;
    }
}
