<?php

namespace App\Modules\Customers\Listeners;

use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Events\OrderPaid;

/** Order paid → the customer's visit count, spend and points move. */
class UpdateCustomerWhenPaid
{
    public function __construct(private readonly CustomerService $customers) {}

    public function handle(OrderPaid $event): void
    {
        $this->customers->recordPaidOrder($event->order);
    }
}
