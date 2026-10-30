<?php

namespace App\Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Completed = 'completed';
    case Refunded = 'refunded';
}
