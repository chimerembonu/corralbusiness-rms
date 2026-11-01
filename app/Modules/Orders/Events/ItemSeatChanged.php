<?php

namespace App\Modules\Orders\Events;

class ItemSeatChanged extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_seat_changed';
    }
}
