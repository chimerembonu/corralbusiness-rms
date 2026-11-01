<?php

namespace App\Modules\Customers\Enums;

enum AccountTransactionType: string
{
    case Charge = 'charge';          // an order put on the account
    case Payment = 'payment';        // money received against the account
    case Adjustment = 'adjustment';  // manager correction / write-off

    public function label(): string
    {
        return __('customers::customers.account.type.'.$this->value);
    }
}
