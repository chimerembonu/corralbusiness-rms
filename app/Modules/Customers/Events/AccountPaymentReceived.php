<?php

namespace App\Modules\Customers\Events;

class AccountPaymentReceived extends AccountEvent
{
    public function outboxEventType(): string
    {
        return 'customers.account_payment_received';
    }
}
