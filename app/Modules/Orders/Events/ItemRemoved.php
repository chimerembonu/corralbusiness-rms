<?php

namespace App\Modules\Orders\Events;

class ItemRemoved extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_removed';
    }
}
