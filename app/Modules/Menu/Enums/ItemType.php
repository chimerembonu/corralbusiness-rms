<?php

namespace App\Modules\Menu\Enums;

enum ItemType: string
{
    case Standard = 'standard';
    case Combo = 'combo';

    public function label(): string
    {
        return __('menu::menu.item_type.'.$this->value);
    }
}
