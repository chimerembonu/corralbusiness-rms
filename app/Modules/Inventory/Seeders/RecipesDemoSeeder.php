<?php

namespace App\Modules\Inventory\Seeders;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\ModifierRecipeLine;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\Modifier;

/**
 * Demo recipes (E5.1) for the popular dishes and their main extras, using the twenty demo ingredients. Rough portion sizes for a
 * demo, not a real kitchen's figures. Re-runnable: a dish's demo recipe is replaced, not added twice.
 */
class RecipesDemoSeeder
{
    public function run(): int
    {
        // dish code => [[ingredient, amount, unit, size (null = every size)]]
        $recipes = [
            'JOL' => [['Long-grain rice', 200, 'g'], ['Tomatoes', 80, 'g'], ['Onions', 30, 'g'], ['Vegetable oil', 30, 'ml'], ['Fresh pepper', 10, 'g'], ['Long-grain rice', 100, 'g', 'Large']],
            'FRR' => [['Long-grain rice', 200, 'g'], ['Eggs', 1, 'pcs'], ['Onions', 20, 'g'], ['Vegetable oil', 25, 'ml'], ['Long-grain rice', 100, 'g', 'Large']],
            'WAA' => [['Long-grain rice', 150, 'g'], ['Black-eyed beans', 80, 'g'], ['Gari', 30, 'g'], ['Long-grain rice', 70, 'g', 'Large'], ['Black-eyed beans', 40, 'g', 'Large']],
            'BKT' => [['Corn dough', 300, 'g'], ['Tilapia', 450, 'g'], ['Fresh pepper', 15, 'g'], ['Onions', 30, 'g'], ['Tilapia', 450, 'g', '2 tilapia'], ['Corn dough', 300, 'g', '2 tilapia']],
            'RED' => [['Black-eyed beans', 150, 'g'], ['Palm oil', 40, 'ml'], ['Ripe plantain', 200, 'g'], ['Onions', 20, 'g']],
            'GCH' => [['Chicken (whole)', 400, 'g'], ['Ginger', 10, 'g'], ['Garlic', 5, 'g'], ['Chicken (whole)', 400, 'g', 'Full']],
            'KEL' => [['Ripe plantain', 250, 'g'], ['Ginger', 10, 'g'], ['Fresh pepper', 5, 'g'], ['Vegetable oil', 40, 'ml']],
            'SOB' => [['Dried hibiscus (sobolo)', 15, 'g'], ['Ginger', 5, 'g'], ['Sugar', 40, 'g']],
        ];

        $ingredients = Ingredient::query()->get()->keyBy('name');
        $count = 0;
        foreach ($recipes as $code => $lines) {
            $item = Item::query()->with('variants')->where('code', $code)->first();
            if (! $item) {
                continue;
            }
            RecipeLine::query()->where('item_id', $item->id)->forBranch()->delete();
            foreach ($lines as $i => $line) {
                [$name, $amount, $unit] = $line;
                $size = $line[3] ?? null;
                $ingredient = $ingredients->get($name);
                $variant = $size !== null ? $item->variants->firstWhere('name', $size) : null;
                if (! $ingredient || ($size !== null && ! $variant)) {
                    continue;
                }
                RecipeLine::create(['item_id' => $item->id, 'variant_id' => $variant?->id, 'ingredient_id' => $ingredient->id, 'quantity' => $amount, 'unit' => $unit, 'sort_order' => $i]);
                $count++;
            }
        }

        // Extras: what one of each uses, on top of the dish (e.g. the protein chosen with jollof).
        $extras = [
            'Chicken' => [['Chicken (whole)', 150, 'g']],
            'Beef' => [['Beef', 120, 'g']],
            'Fried fish' => [['Tilapia', 150, 'g'], ['Vegetable oil', 20, 'ml']],
            'Egg' => [['Eggs', 1, 'pcs']],
            'Boiled egg' => [['Eggs', 1, 'pcs']],
            'Fried plantain' => [['Ripe plantain', 100, 'g'], ['Vegetable oil', 15, 'ml']],
            'Extra plantain' => [['Ripe plantain', 100, 'g'], ['Vegetable oil', 15, 'ml']],
            'Gari' => [['Gari', 30, 'g']],
        ];
        foreach ($extras as $name => $lines) {
            foreach (Modifier::query()->where('name', $name)->get() as $modifier) {
                ModifierRecipeLine::query()->where('modifier_id', $modifier->id)->forBranch()->delete();
                foreach ($lines as $i => [$ingredientName, $amount, $unit]) {
                    if ($ingredient = $ingredients->get($ingredientName)) {
                        ModifierRecipeLine::create(['modifier_id' => $modifier->id, 'ingredient_id' => $ingredient->id, 'quantity' => $amount, 'unit' => $unit, 'sort_order' => $i]);
                        $count++;
                    }
                }
            }
        }

        return $count;
    }
}
