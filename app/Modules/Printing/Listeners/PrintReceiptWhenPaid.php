<?php

namespace App\Modules\Printing\Listeners;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Printing\Services\PrintService;

class PrintReceiptWhenPaid
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(OrderPaid $event): void
    {
        $this->printing->printReceipt($event->order);
    }
}
