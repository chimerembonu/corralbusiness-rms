<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

abstract class OrderItemEvent extends OrderEvent
{
    public function __construct(Order $order, public readonly OrderItem $item, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    protected function extraPayload(): array
    {
        return [
            'item' => [
                'id' => $this->item->id,
                'menu_item_id' => $this->item->item_id,
                'name' => $this->item->displayName(),
                'quantity' => $this->item->quantity,
                'line_total' => $this->item->line_total->amount,
                'status' => $this->item->status->value,
            ],
        ];
    }
}
