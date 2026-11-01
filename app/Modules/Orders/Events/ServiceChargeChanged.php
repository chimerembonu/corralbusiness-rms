<?php

namespace App\Modules\Orders\Events;

class ServiceChargeChanged extends OrderEvent
{
    public function outboxEventType(): string
    {
        return 'orders.service_charge_changed';
    }

    protected function extraPayload(): array
    {
        return ['service_charge' => $this->order->service_charge->amount, 'waived' => $this->order->service_charge_waived];
    }
}
