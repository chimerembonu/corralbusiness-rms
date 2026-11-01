<?php

namespace App\Modules\Orders\Events;

class OrderRefunded extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.refunded';
    }

    protected function extraPayload(): array
    {
        return ['refunded_total' => $this->order->refunded_total->amount];
    }
}
