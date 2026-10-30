<?php

namespace App\Modules\Payments\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'momo';
    case Card = 'card';
    case Account = 'account';

    public function label(): string
    {
        return __('payments::payments.method.'.$this->value);
    }
}
