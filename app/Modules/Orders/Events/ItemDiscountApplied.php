<?php

namespace App\Modules\Orders\Events;

class ItemDiscountApplied extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_discount_applied';
    }
}
