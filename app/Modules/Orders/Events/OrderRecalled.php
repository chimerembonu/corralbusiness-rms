<?php

namespace App\Modules\Orders\Events;

class OrderRecalled extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.recalled';
    }
}
