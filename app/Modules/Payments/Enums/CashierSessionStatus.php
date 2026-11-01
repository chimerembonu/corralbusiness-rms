<?php

namespace App\Modules\Payments\Enums;

enum CashierSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return __('payments::payments.cash.status.'.$this->value);
    }
}
