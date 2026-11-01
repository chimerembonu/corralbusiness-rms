<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\StockService;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Events\ItemVoided;
use App\Modules\Orders\Events\OrderVoided;
use App\Modules\Staff\Models\User;

/** A sent line is voided, or a sent order cancelled → counted items come back into stock. */
class RestockWhenVoided
{
    public function __construct(private readonly StockService $stock) {}

    public function handle(ItemVoided|OrderVoided $event): void
    {
        $by = $event->byUserId ? User::query()->find($event->byUserId) : null;

        if ($event instanceof ItemVoided) {
            $this->stock->restockLine($event->order, $event->item, $by);

            return;
        }

        foreach ($event->order->items()->where('status', '!=', OrderItemStatus::Voided->value)->get() as $line) {
            $this->stock->restockLine($event->order, $line, $by);
        }
    }
}
