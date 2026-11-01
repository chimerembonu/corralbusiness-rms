<?php

namespace App\Modules\Payments\Enums;

enum DayCloseStatus: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return __('payments::payments.day.status.'.$this->value);
    }
}
