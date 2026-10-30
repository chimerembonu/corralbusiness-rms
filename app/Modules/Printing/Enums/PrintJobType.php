<?php

namespace App\Modules\Printing\Enums;

enum PrintJobType: string
{
    case Receipt = 'receipt';
    case KitchenTicket = 'kitchen_ticket';
    case Test = 'test';
    case Drawer = 'drawer';

    public function label(): string
    {
        return __('printing::printing.job_type.'.$this->value);
    }
}
