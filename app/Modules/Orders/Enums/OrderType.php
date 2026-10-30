<?php

namespace App\Modules\Orders\Enums;

enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function label(): string
    {
        return __('orders::orders.type.'.$this->value);
    }
}
