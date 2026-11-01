<?php

namespace App\Modules\Orders\Events;

class OrderCoversChanged extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.covers_changed';
    }

    protected function extraPayload(): array
    {
        return ['covers' => $this->order->covers];
    }
}
