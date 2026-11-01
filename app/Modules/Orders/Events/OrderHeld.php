<?php

namespace App\Modules\Orders\Events;

class OrderHeld extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.held';
    }

    protected function extraPayload(): array
    {
        return ['customer_name' => $this->order->customer_name];
    }
}
