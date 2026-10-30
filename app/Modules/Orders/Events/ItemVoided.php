<?php

namespace App\Modules\Orders\Events;

class ItemVoided extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_voided';
    }
}
