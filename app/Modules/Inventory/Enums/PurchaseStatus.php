<?php

namespace App\Modules\Inventory\Enums;

enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('inventory::inventory.purchase.status.'.$this->value);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Ordered], true);
    }
}
