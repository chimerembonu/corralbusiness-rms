<?php

namespace App\Modules\Orders\Events;

class DeliveryDetailsChanged extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.delivery_details_changed';
    }

    protected function extraPayload(): array
    {
        // No name, phone or address: the outbox is kept and later synced; the order itself holds them (E9.6 review F1).
        return [
            'customer_id' => $this->order->customer_id,
            'has_address' => filled($this->order->delivery_address),
            'delivery_fee' => $this->order->delivery_fee->amount,
        ];
    }
}
