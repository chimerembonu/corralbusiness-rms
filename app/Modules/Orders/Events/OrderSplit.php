<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;

/** Fired on the NEW order that was split off; $fromOrderId is the original. */
class OrderSplit extends OrderEvent
{
    public function __construct(Order $order, public readonly string $fromOrderId, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'orders.split';
    }

    protected function extraPayload(): array
    {
        return ['from_order_id' => $this->fromOrderId];
    }
}
