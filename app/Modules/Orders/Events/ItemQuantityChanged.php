<?php

namespace App\Modules\Orders\Events;

class ItemQuantityChanged extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_quantity_changed';
    }
}
