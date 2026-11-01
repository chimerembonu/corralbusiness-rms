<?php

namespace App\Modules\Printing\Listeners;

use App\Modules\Payments\Events\CashMovementRecorded;
use App\Modules\Printing\Services\PrintService;

/** Pay-in / pay-out → the drawer pops so the cash can go in or out. */
class OpenDrawerOnCashMovement
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(CashMovementRecorded $event): void
    {
        $this->printing->openDrawer($event->movement->branch_id, $event->movement);
    }
}
