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
        return [
            'customer_name' => $this->order->customer_name,
            'customer_phone' => $this->order->customer_phone,
            'delivery_address' => $this->order->delivery_address,
            'delivery_fee' => $this->order->delivery_fee->amount,
        ];
    }
}
