<?php

namespace App\Modules\Orders\Events;

class OrderPaid extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.paid';
    }
}
