<?php

namespace App\Modules\Menu\Seeders;

use App\Modules\Menu\Enums\ItemType;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\ComboItem;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Models\Modifier;
use App\Modules\Menu\Models\ModifierGroup;
use App\Support\Money\Money;

/**
 * A realistic Ghanaian restaurant menu (CLAUDE.md §5). Prices in GHS.
 * Re-runnable: items are matched by their short code and updated, never duplicated.
 * Must run inside a tenant context (Tenancy::set(...)).
 */
class MenuDemoSeeder
{
    /** @return array<string, int> counts */
    public function run(): array
    {
        $groups = $this->modifierGroups();
        $categories = $this->categories();
        $items = $this->items($categories, $groups);
        $this->combos($categories, $items, $groups);

        return [
            'categories' => Category::count(),
            'items' => Item::count(),
            'variants' => ItemVariant::count(),
            'modifier_groups' => ModifierGroup::count(),
            'modifiers' => Modifier::count(),
        ];
    }

    /** @return array<string, ModifierGroup> */
    private function modifierGroups(): array
    {
        $definitions = [
            'protein' => ['name' => 'Choose a protein', 'min' => 1, 'max' => 1, 'options' => [
                ['Chicken', 15], ['Beef', 15], ['Fried fish', 18], ['Goat', 20], ['Egg', 5], ['No protein', 0],
            ]],
            'waakye_extras' => ['name' => 'Waakye extras', 'min' => 0, 'max' => 0, 'qty' => true, 'options' => [
                ['Boiled egg', 5], ['Wele', 8], ['Spaghetti', 4], ['Gari', 2], ['Shito', 2], ['Salad', 5], ['Fried plantain', 6],
            ]],
            'spice' => ['name' => 'Spice level', 'min' => 1, 'max' => 1, 'options' => [
                ['Mild', 0], ['Medium', 0], ['Hot', 0],
            ]],
            'soup' => ['name' => 'Choose a soup', 'min' => 1, 'max' => 1, 'options' => [
                ['Light soup', 0], ['Groundnut soup', 5], ['Palm nut soup', 5],
            ]],
            'sides' => ['name' => 'Extras', 'min' => 0, 'max' => 3, 'options' => [
                ['Extra plantain', 8], ['Extra shito', 3], ['Coleslaw', 8], ['Extra sauce', 4],
            ]],
            'drink_choice' => ['name' => 'Choose a drink', 'min' => 1, 'max' => 1, 'options' => [
                ['Coca-Cola', 0], ['Fanta', 0], ['Sprite', 0], ['Bottled water', 0], ['Sobolo', 3],
            ]],
            'temperature' => ['name' => 'Temperature', 'min' => 1, 'max' => 1, 'options' => [
                ['Chilled', 0], ['Room temperature', 0],
            ]],
        ];

        $groups = [];
        foreach ($definitions as $key => $def) {
            $group = ModifierGroup::query()->updateOrCreate(['name' => $def['name']], [
                'min_select' => $def['min'],
                'max_select' => $def['max'],
                'allow_quantity' => $def['qty'] ?? false,
                'is_active' => true,
            ]);

            foreach ($def['options'] as $i => [$name, $price]) {
                Modifier::query()->updateOrCreate(['modifier_group_id' => $group->id, 'name' => $name], [
                    'price' => Money::fromDecimal($price),
                    'is_default' => $i === 0 && $def['min'] > 0,
                    'sort_order' => $i,
                    'is_active' => true,
                ]);
            }

            $groups[$key] = $group;
        }

        return $groups;
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $definitions = [
            'rice' => ['Rice Dishes', '#f59e0b', null],
            'local' => ['Local Favourites', '#16a34a', null],
            'grills' => ['Grills & Fish', '#dc2626', null],
            'soups' => ['Soups & Swallow', '#9333ea', null],
            'sides' => ['Sides & Snacks', '#0891b2', null],
            'breakfast' => ['Breakfast', '#eab308', ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '06:00', 'to' => '11:00']],
            'softdrinks' => ['Soft Drinks & Juices', '#2563eb', null],
            'beers' => ['Beers & Spirits', '#7c2d12', null],
            'combos' => ['Combos', '#db2777', null],
        ];

        $categories = [];
        foreach (array_values($definitions) as $i => [$name, $color, $availability]) {
            $key = array_keys($definitions)[$i];
            $categories[$key] = Category::query()->updateOrCreate(['name' => $name], [
                'color' => $color,
                'availability' => $availability,
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }

        return $categories;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, ModifierGroup>  $groups
     * @return array<string, Item>
     */
    private function items(array $categories, array $groups): array
    {
        // code => [category, name, price, options]
        $definitions = [
            'JOL' => ['rice', 'Jollof Rice', 45, ['variants' => [['Regular', 45, true], ['Large', 60]], 'groups' => ['protein', 'spice', 'sides'], 'tags' => ['popular', 'spicy'], 'desc' => 'Smoky tomato jollof with fried plantain.']],
            'FRR' => ['rice', 'Fried Rice', 45, ['variants' => [['Regular', 45, true], ['Large', 60]], 'groups' => ['protein', 'sides'], 'allergens' => ['eggs', 'soy']]],
            'WAA' => ['local', 'Waakye', 35, ['variants' => [['Regular', 35, true], ['Large', 50]], 'groups' => ['protein', 'waakye_extras'], 'tags' => ['popular'], 'availability' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '06:00', 'to' => '15:00'], 'desc' => 'Rice and beans with shito, gari and stew.']],
            'BKT' => ['grills', 'Banku & Grilled Tilapia', 95, ['variants' => [['1 tilapia', 95, true], ['2 tilapia', 170]], 'groups' => ['spice'], 'allergens' => ['fish'], 'tags' => ['popular'], 'desc' => 'Charcoal-grilled tilapia with banku, pepper and onions.']],
            'KEN' => ['local', 'Kenkey & Fried Fish', 50, ['allergens' => ['fish'], 'groups' => ['spice']]],
            'RED' => ['local', 'Red Red', 40, ['tags' => ['vegetarian'], 'groups' => ['protein'], 'desc' => 'Beans stew with fried ripe plantain.']],
            'FUF' => ['soups', 'Fufu & Soup', 60, ['groups' => ['soup', 'protein', 'spice'], 'allergens' => ['peanuts'], 'desc' => 'Pounded fufu with your choice of soup.']],
            'OMO' => ['soups', 'Omo Tuo & Groundnut Soup', 55, ['allergens' => ['peanuts'], 'groups' => ['protein']]],
            'TZE' => ['soups', 'Tuo Zaafi (TZ)', 50, ['groups' => ['protein', 'spice']]],
            'GCH' => ['grills', 'Grilled Chicken', 65, ['variants' => [['Half', 65, true], ['Full', 120]], 'groups' => ['spice', 'sides']]],
            'WNG' => ['grills', 'Chicken Wings (6 pcs)', 55, ['groups' => ['spice']]],
            'KHB' => ['grills', 'Beef Khebab', 15, ['groups' => ['spice'], 'tags' => ['spicy'], 'allergens' => ['peanuts'], 'desc' => 'Per stick, with suya spice.']],
            'KEL' => ['sides', 'Kelewele', 20, ['tags' => ['spicy', 'vegetarian'], 'availability' => ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '15:00', 'to' => '23:30']]],
            'PLN' => ['sides', 'Fried Plantain', 15, ['tags' => ['vegetarian']]],
            'YAM' => ['sides', 'Yam Chips', 25, ['tags' => ['vegetarian'], 'groups' => ['sides']]],
            'SAL' => ['sides', 'Garden Salad', 20, ['tags' => ['vegetarian']]],
            'SPR' => ['sides', 'Spring Rolls (4 pcs)', 25, ['allergens' => ['gluten']]],
            'HAU' => ['breakfast', 'Hausa Koko & Koose', 15, ['tags' => ['vegetarian']]],
            'TEA' => ['breakfast', 'Tea / Milo & Bread', 20, ['allergens' => ['dairy', 'gluten']]],
            'OMG' => ['breakfast', 'Omelette & Bread', 30, ['allergens' => ['eggs', 'gluten']]],
            'COK' => ['softdrinks', 'Coca-Cola', 10, ['groups' => ['temperature'], 'track' => true]],
            'FAN' => ['softdrinks', 'Fanta', 10, ['groups' => ['temperature'], 'track' => true]],
            'SPT' => ['softdrinks', 'Sprite', 10, ['groups' => ['temperature'], 'track' => true]],
            'MLT' => ['softdrinks', 'Malta Guinness', 14, ['groups' => ['temperature'], 'track' => true]],
            'WAT' => ['softdrinks', 'Bottled Water', 5, ['variants' => [['500 ml', 5, true], ['1.5 L', 10]], 'track' => true]],
            'SOB' => ['softdrinks', 'Sobolo', 12, ['tags' => ['popular'], 'desc' => 'Hibiscus drink with ginger and pineapple.']],
            'JUI' => ['softdrinks', 'Fresh Juice', 25, ['variants' => [['Pineapple', 25, true], ['Watermelon', 25], ['Mango', 28]]]],
            'CLB' => ['beers', 'Club Beer', 20, ['track' => true]],
            'STR' => ['beers', 'Star Beer', 20, ['track' => true]],
            'GUI' => ['beers', 'Guinness', 22, ['track' => true]],
            'SMR' => ['beers', 'Smirnoff Ice', 22, ['track' => true]],
        ];

        $items = [];
        foreach ($definitions as $code => [$categoryKey, $name, $price, $options]) {
            $item = Item::query()->updateOrCreate(['code' => $code], [
                'category_id' => $categories[$categoryKey]->id,
                'type' => ItemType::Standard,
                'name' => $name,
                'description' => $options['desc'] ?? null,
                'price' => Money::fromDecimal($price),
                'allergens' => $options['allergens'] ?? null,
                'tags' => $options['tags'] ?? null,
                'availability' => $options['availability'] ?? null,
                'track_stock' => $options['track'] ?? false,
                'taxable' => true,
                'is_active' => true,
            ]);

            foreach ($options['variants'] ?? [] as $i => $variant) {
                ItemVariant::query()->updateOrCreate(['item_id' => $item->id, 'name' => $variant[0]], [
                    'price' => Money::fromDecimal($variant[1]),
                    'is_default' => $variant[2] ?? false,
                    'sort_order' => $i,
                    'is_active' => true,
                ]);
            }

            $this->attachGroups($item, $options['groups'] ?? [], $groups);
            $items[$code] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Item>  $items
     * @param  array<string, ModifierGroup>  $groups
     */
    private function combos(array $categories, array $items, array $groups): void
    {
        $definitions = [
            'CMB1' => ['Jollof & Chicken Combo', 70, 'Regular jollof, half grilled chicken and a drink.', [['JOL', 'Regular', 1], ['GCH', 'Half', 1]], ['drink_choice', 'spice']],
            'CMB2' => ['Waakye Lunch Deal', 45, 'Regular waakye with egg and a drink.', [['WAA', 'Regular', 1]], ['drink_choice', 'protein']],
            'CMB3' => ['Family Tilapia Platter', 320, 'Two grilled tilapia, two large jollof, kelewele and four drinks.', [['BKT', '2 tilapia', 1], ['JOL', 'Large', 2], ['KEL', null, 1]], ['spice']],
        ];

        foreach ($definitions as $code => [$name, $price, $desc, $contents, $groupKeys]) {
            $combo = Item::query()->updateOrCreate(['code' => $code], [
                'category_id' => $categories['combos']->id,
                'type' => ItemType::Combo,
                'name' => $name,
                'description' => $desc,
                'price' => Money::fromDecimal($price),
                'tags' => ['combo'],
                'taxable' => true,
                'is_active' => true,
            ]);

            foreach ($contents as $i => [$itemCode, $variantName, $qty]) {
                $item = $items[$itemCode];
                $variant = $variantName ? ItemVariant::query()->where('item_id', $item->id)->where('name', $variantName)->first() : null;

                ComboItem::query()->updateOrCreate(['combo_id' => $combo->id, 'item_id' => $item->id, 'variant_id' => $variant?->id], [
                    'quantity' => $qty,
                    'sort_order' => $i,
                ]);
            }

            $this->attachGroups($combo, $groupKeys, $groups);
        }
    }

    /** @param  array<string, ModifierGroup>  $groups */
    private function attachGroups(Item $item, array $keys, array $groups): void
    {
        $sync = [];
        foreach ($keys as $i => $key) {
            $sync[$groups[$key]->id] = ['sort_order' => $i];
        }

        $item->modifierGroups()->sync($sync);
    }
}
