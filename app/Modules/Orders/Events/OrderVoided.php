<?php

namespace App\Modules\Orders\Events;

class OrderVoided extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.voided';
    }
}
