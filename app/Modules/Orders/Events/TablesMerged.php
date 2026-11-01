<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;

/**
 * Table $fromTableId was joined onto the table of $order. If the joined table had its own
 * open order, that order's lines now live on $order and $sourceOrderId is marked merged.
 */
class TablesMerged extends OrderEvent
{
    public function __construct(Order $order, public readonly string $fromTableId, public readonly string $intoTableId, public readonly ?string $sourceOrderId, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'orders.tables_merged';
    }

    protected function extraPayload(): array
    {
        return ['from_table_id' => $this->fromTableId, 'into_table_id' => $this->intoTableId, 'source_order_id' => $this->sourceOrderId];
    }
}
