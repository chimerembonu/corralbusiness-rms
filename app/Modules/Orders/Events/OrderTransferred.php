<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;

/** A dine-in order moved from one table to another. */
class OrderTransferred extends OrderEvent
{
    public function __construct(Order $order, public readonly ?string $fromTableId, public readonly string $toTableId, public readonly ?string $toTableName, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'orders.transferred';
    }

    protected function extraPayload(): array
    {
        return ['from_table_id' => $this->fromTableId, 'to_table_id' => $this->toTableId];
    }
}
