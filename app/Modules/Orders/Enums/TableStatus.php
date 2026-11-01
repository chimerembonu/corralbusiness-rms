<?php

namespace App\Modules\Orders\Enums;

/** What a table looks like on the floor plan right now. */
enum TableStatus: string
{
    case Free = 'free';
    case Seated = 'seated';     // open order, nothing sent to the kitchen yet
    case Ordered = 'ordered';   // food sent to the kitchen
    case Merged = 'merged';     // joined onto another table

    public function label(): string
    {
        return __('orders::orders.table_status.'.$this->value);
    }
}
