<?php

namespace App\Modules\Orders\Events;

class OrderDiscountApplied extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.discount_applied';
    }
}
