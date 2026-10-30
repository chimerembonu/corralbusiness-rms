<?php

return [
    'navigation_group' => 'Inventory',
    'ingredient' => 'Ingredient',
    'ingredients' => 'Ingredients',
    'units' => ['kg' => 'Kilogram (kg)', 'g' => 'Gram (g)', 'l' => 'Litre (L)', 'ml' => 'Millilitre (ml)', 'pcs' => 'Pieces'],
    'fields' => [
        'name' => 'Name',
        'code' => 'Code',
        'unit' => 'Unit',
        'cost_per_unit' => 'Cost per unit (GH₵)',
        'on_hand' => 'In stock',
        'reorder_level' => 'Re-order when below',
        'is_active' => 'Active',
        'low' => 'Low stock',
    ],
];
