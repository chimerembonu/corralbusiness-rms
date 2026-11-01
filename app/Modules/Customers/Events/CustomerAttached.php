<?php

namespace App\Modules\Customers\Events;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Events\OrderEvent;
use App\Modules\Orders\Models\Order;

class CustomerAttached extends OrderEvent
{
    public function __construct(Order $order, public readonly Customer $customer, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'customers.attached';
    }

    protected function extraPayload(): array
    {
        return ['customer_id' => $this->customer->id, 'phone' => $this->customer->phone];
    }
}
