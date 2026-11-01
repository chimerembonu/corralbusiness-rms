<?php

namespace App\Modules\Payments\Enums;

enum CashMovementType: string
{
    case PayIn = 'pay_in';
    case PayOut = 'pay_out';

    public function label(): string
    {
        return __('payments::payments.cash.movement.'.$this->value);
    }
}
