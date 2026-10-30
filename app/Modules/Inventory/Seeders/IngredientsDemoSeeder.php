<?php

namespace App\Modules\Inventory\Seeders;

use App\Modules\Inventory\Models\Ingredient;
use App\Support\Money\Money;

/** Twenty ingredients a Ghanaian kitchen stocks, with units and rough 2026 costs. Re-runnable. */
class IngredientsDemoSeeder
{
    public function run(): int
    {
        $items = [
            // name, unit, cost per unit (GHS), on hand, reorder level
            ['Long-grain rice', 'kg', 22, 80, 25],
            ['Tomatoes', 'kg', 18, 30, 10],
            ['Onions', 'kg', 12, 25, 8],
            ['Chicken (whole)', 'kg', 48, 40, 15],
            ['Beef', 'kg', 70, 20, 8],
            ['Tilapia', 'kg', 55, 30, 10],
            ['Palm oil', 'l', 32, 20, 5],
            ['Vegetable oil', 'l', 28, 25, 8],
            ['Ripe plantain', 'kg', 10, 35, 10],
            ['Cassava', 'kg', 6, 40, 10],
            ['Yam', 'kg', 14, 30, 10],
            ['Black-eyed beans', 'kg', 20, 20, 6],
            ['Gari', 'kg', 15, 15, 5],
            ['Fresh pepper', 'kg', 25, 8, 2],
            ['Ginger', 'kg', 30, 5, 1],
            ['Garlic', 'kg', 35, 4, 1],
            ['Dried hibiscus (sobolo)', 'kg', 40, 6, 2],
            ['Sugar', 'kg', 16, 25, 8],
            ['Eggs', 'pcs', 2.5, 180, 60],
            ['Corn dough', 'kg', 9, 30, 10],
        ];

        foreach ($items as $i => [$name, $unit, $cost, $onHand, $reorder]) {
            Ingredient::query()->updateOrCreate(['name' => $name], [
                'code' => 'ING'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'unit' => $unit,
                'cost_per_unit' => Money::fromDecimal($cost),
                'on_hand' => $onHand,
                'reorder_level' => $reorder,
                'is_active' => true,
            ]);
        }

        return Ingredient::count();
    }
}
