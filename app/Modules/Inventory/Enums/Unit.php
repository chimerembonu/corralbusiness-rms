<?php

namespace App\Modules\Inventory\Enums;

enum Unit: string
{
    case Kilogram = 'kg';
    case Gram = 'g';
    case Litre = 'l';
    case Millilitre = 'ml';
    case Piece = 'pcs';
    case Bottle = 'bottle';
    case Can = 'can';
    case Crate = 'crate';
    case Bag = 'bag';
    case Tin = 'tin';
    case Pack = 'pack';
    case Bunch = 'bunch';
    case Portion = 'portion';

    public function label(): string
    {
        return __('inventory::inventory.units.'.$this->value);
    }

    /** Short form for tables and count sheets: "kg", "L", "pcs"… */
    public function short(): string
    {
        return __('inventory::inventory.unit_short.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
