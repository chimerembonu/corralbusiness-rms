<?php

namespace App\Modules\Staff\Enums;

enum TimeEntrySource: string
{
    case Clock = 'clock';   // the /clock PIN screen
    case Pos = 'pos';       // the button in the till's top bar
    case Admin = 'admin';   // typed in by a manager
    case Auto = 'auto';     // closed automatically (forgot to clock out)

    public function label(): string
    {
        return __('staff::staff.source.'.$this->value);
    }
}
