<?php

namespace App\Modules\Kitchen\Listeners;

use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Orders\Events\CourseFired;

class FireHeldCourse
{
    public function __construct(private readonly KitchenService $kitchen) {}

    public function handle(CourseFired $event): void
    {
        $this->kitchen->fireLines($event->items->pluck('id')->all());
    }
}
