<?php

namespace App\Modules\Printing\Listeners;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Printing\Services\PrintService;

/** Cash in → the drawer pops on the till's receipt printer (if it has a drawer). */
class OpenDrawerOnCashPayment
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(PaymentRecorded $event): void
    {
        if ($event->payment->method !== PaymentMethod::Cash) {
            return;
        }

        $this->printing->openDrawer($event->payment->branch_id, $event->payment);
    }
}
