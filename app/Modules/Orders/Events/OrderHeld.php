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
        return ['named' => filled($this->order->customer_name)]; // never the name itself (E9.6 review F1)
    }
}
