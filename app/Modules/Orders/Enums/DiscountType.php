<?php

namespace App\Modules\Orders\Enums;

enum DiscountType: string
{
    case Percent = 'percent'; // value in basis points: 10 % = 1000
    case Fixed = 'fixed';     // value in pesewas
}
