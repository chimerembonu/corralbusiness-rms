<?php

namespace App\Modules\Kitchen\Listeners;

use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Orders\Events\OrderVoided;

/** A sent order is cancelled at the till → its tickets leave the kitchen screens (test report H8). */
class CancelTicketsWhenVoided
{
    public function __construct(private readonly KitchenService $kitchen) {}

    public function handle(OrderVoided $event): void
    {
        $this->kitchen->cancelOrder($event->order);
    }
}
