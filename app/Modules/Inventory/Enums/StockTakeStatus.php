<?php

namespace App\Modules\Inventory\Enums;

enum StockTakeStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('inventory::inventory.stock_take.status.'.$this->value);
    }
}
