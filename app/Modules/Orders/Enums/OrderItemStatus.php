<?php

namespace App\Modules\Orders\Enums;

enum OrderItemStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Voided = 'voided';
}
