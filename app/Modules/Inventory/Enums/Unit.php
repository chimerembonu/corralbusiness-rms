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

    /**
     * The same amount in another unit: kg ↔ g and L ↔ ml (recipes say "200 g" of rice counted in kg).
     * Null when the units cannot be converted (kg to bottles, say).
     */
    public function convert(float $quantity, self $to): ?float
    {
        if ($this === $to) {
            return $quantity;
        }
        $factors = ['kg' => ['g' => 1000], 'g' => ['kg' => 0.001], 'l' => ['ml' => 1000], 'ml' => ['l' => 0.001]];

        return isset($factors[$this->value][$to->value]) ? $quantity * $factors[$this->value][$to->value] : null;
    }

    /** @return array<int, self> units a recipe may use for an ingredient counted in this unit */
    public function compatible(): array
    {
        return array_values(array_filter(self::cases(), fn (self $u) => $u->convert(1, $this) !== null));
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
