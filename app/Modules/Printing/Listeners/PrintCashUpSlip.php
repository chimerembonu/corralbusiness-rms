<?php

namespace App\Modules\Printing\Listeners;

use App\Modules\Payments\Events\CashierSessionClosed;
use App\Modules\Printing\Services\PrintService;

/** Cash-up done → the slip prints on the till's receipt printer. */
class PrintCashUpSlip
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(CashierSessionClosed $event): void
    {
        $this->printing->printCashUp($event->session);
    }
}
