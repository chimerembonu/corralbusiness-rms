<?php

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case Open = 'open';     // being built, nothing sent yet
    case Sent = 'sent';     // at least one item sent to the kitchen
    case Paid = 'paid';     // fully paid
    case Void = 'void';
    case Merged = 'merged'; // its lines were moved onto another order (table merge)

    public function label(): string
    {
        return __('orders::orders.status.'.$this->value);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Sent], true);
    }
}
