<?php

namespace App\Modules\Orders\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';       // being prepared, no rider yet
    case Assigned = 'assigned';     // rider chosen, still in the restaurant
    case Out = 'out';               // on the road
    case Delivered = 'delivered';
    case Failed = 'failed';         // customer not reached, wrong address…

    public function label(): string
    {
        return __('orders::orders.delivery_status.'.$this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Assigned, self::Out], true);
    }
}
