<?php

namespace App\Modules\Orders\Events;

class ItemAdded extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_added';
    }
}
