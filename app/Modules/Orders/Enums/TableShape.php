<?php

namespace App\Modules\Orders\Enums;

enum TableShape: string
{
    case Square = 'square';
    case Round = 'round';
    case Rect = 'rect';

    public function label(): string
    {
        return __('orders::orders.shape.'.$this->value);
    }

    /** @return array<string, string> value → label, for selects */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $s) => $s->value, self::cases()),
            array_map(fn (self $s) => $s->label(), self::cases()),
        );
    }
}
