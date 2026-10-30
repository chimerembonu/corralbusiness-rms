<?php

namespace App\Modules\Kitchen\Listeners;

use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Orders\Events\ItemVoided;

class StrikeVoidedLine
{
    public function __construct(private readonly KitchenService $kitchen) {}

    public function handle(ItemVoided $event): void
    {
        $this->kitchen->voidLine($event->item);
    }
}
