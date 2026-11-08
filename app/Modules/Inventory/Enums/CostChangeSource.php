<?php

namespace App\Modules\Inventory\Enums;

/** Why an ingredient's cost changed (E5.2). */
enum CostChangeSource: string
{
    case Opening = 'opening';    // the cost it was created with
    case Purchase = 'purchase';  // a delivery: the new average of old stock and the delivery
    case Manual = 'manual';      // changed by hand, with a reason
    case Import = 'import';      // a spreadsheet import

    public function label(): string
    {
        return __('inventory::inventory.costs.source.'.$this->value);
    }
}
