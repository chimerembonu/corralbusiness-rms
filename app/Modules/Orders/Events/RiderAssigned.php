<?php

namespace App\Modules\Orders\Events;

class RiderAssigned extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.rider_assigned';
    }

    protected function extraPayload(): array
    {
        return ['rider_id' => $this->order->rider_id];
    }
}
