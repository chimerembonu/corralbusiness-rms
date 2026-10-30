<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Collection;

/** Fired with the lines that were just sent (new lines only when sending again). */
class OrderSentToKitchen extends OrderEvent
{
    /** @param  Collection<int, OrderItem>  $items */
    public function __construct(Order $order, public readonly Collection $items, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'orders.sent_to_kitchen';
    }

    protected function extraPayload(): array
    {
        return ['items' => $this->items->map(fn (OrderItem $i) => ['id' => $i->id, 'name' => $i->displayName(), 'quantity' => $i->quantity])->all()];
    }
}
