<?php

namespace App\Modules\Orders\Events;

class DeliveryStatusChanged extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.delivery_status_changed';
    }

    protected function extraPayload(): array
    {
        return ['delivery_status' => $this->order->delivery_status?->value, 'rider_id' => $this->order->rider_id, 'failure' => $this->order->delivery_failure];
    }
}
