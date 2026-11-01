<?php

namespace App\Modules\Printing\Listeners;

use App\Modules\Payments\Events\DayClosed;
use App\Modules\Printing\Services\PrintService;

/** Day closed → the Z report prints. */
class PrintZReport
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(DayClosed $event): void
    {
        $this->printing->printZReport($event->dayClose);
    }
}
