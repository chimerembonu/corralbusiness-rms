<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Services\StockService;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Staff\Models\User;

/** Lines go to the kitchen → counted menu items (drinks…) go down. */
class DeductStockWhenSent
{
    public function __construct(private readonly StockService $stock) {}

    public function handle(OrderSentToKitchen $event): void
    {
        $this->stock->deductForSale($event->order, $event->items, $event->byUserId ? User::query()->find($event->byUserId) : null);
    }
}
