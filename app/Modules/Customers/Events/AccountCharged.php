<?php

namespace App\Modules\Customers\Events;

class AccountCharged extends AccountEvent
{
    public function outboxEventType(): string
    {
        return 'customers.account_charged';
    }
}
