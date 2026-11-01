<?php

namespace App\Modules\Customers\Listeners;

use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Events\DeliveryDetailsChanged;
use App\Modules\Staff\Models\User;

/** Delivery details saved with a phone → the customer and address are kept for next time. */
class RememberDeliveryCustomer
{
    public function __construct(private readonly CustomerService $customers) {}

    public function handle(DeliveryDetailsChanged $event): void
    {
        $this->customers->rememberFromDelivery($event->order, $event->byUserId ? User::query()->find($event->byUserId) : null);
    }
}
