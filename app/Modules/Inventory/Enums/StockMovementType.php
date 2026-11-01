<?php

namespace App\Modules\Inventory\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';        // first stock entered when the ingredient was created
    case Purchase = 'purchase';      // goods received
    case Sale = 'sale';              // a menu item with "count stock" sold
    case Void = 'void';              // a sold line voided → back in stock
    case Adjustment = 'adjustment';  // manual correction
    case Wastage = 'wastage';        // thrown away, spoiled, spilt
    case StockTake = 'stock_take';   // difference found when counting

    public function label(): string
    {
        return __('inventory::inventory.movement.'.$this->value);
    }
}
