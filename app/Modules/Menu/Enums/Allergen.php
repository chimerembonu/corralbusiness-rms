<?php

namespace App\Modules\Menu\Enums;

enum Allergen: string
{
    case Gluten = 'gluten';
    case Dairy = 'dairy';
    case Eggs = 'eggs';
    case Fish = 'fish';
    case Shellfish = 'shellfish';
    case Peanuts = 'peanuts';
    case TreeNuts = 'tree_nuts';
    case Soy = 'soy';
    case Sesame = 'sesame';

    public function label(): string
    {
        return __('menu::menu.allergens.'.$this->value);
    }

    /** @return array<string, string> value → label */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
