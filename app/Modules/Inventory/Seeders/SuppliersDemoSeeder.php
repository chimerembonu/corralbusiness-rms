<?php

namespace App\Modules\Inventory\Seeders;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Menu\Models\Item;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;

/** Three suppliers, ingredient categories, counted drinks, and one received delivery. Re-runnable. */
class SuppliersDemoSeeder
{
    /** @return array{suppliers: int, tracked_items: int, purchase: ?string} */
    public function run(): array
    {
        $suppliers = [
            'Makola Market Traders' => ['contact_name' => 'Auntie Adwoa', 'phone' => '+233 24 111 2222', 'payment_terms' => 'Cash on delivery'],
            'Accra Fresh Meat & Fish' => ['contact_name' => 'Mr Mensah', 'phone' => '+233 20 333 4444', 'payment_terms' => '7 days'],
            'Twellium Drinks Depot' => ['contact_name' => 'Depot desk', 'phone' => '+233 30 555 6666', 'payment_terms' => '14 days'],
        ];
        $models = [];
        foreach ($suppliers as $name => $attrs) {
            $models[$name] = Supplier::query()->updateOrCreate(['name' => $name], $attrs + ['is_active' => true]);
        }

        $categories = [
            'Dry goods' => ['Long-grain rice', 'Black-eyed beans', 'Gari', 'Sugar', 'Corn dough', 'Dried hibiscus (sobolo)'],
            'Meat & fish' => ['Chicken (whole)', 'Beef', 'Tilapia'],
            'Vegetables & fruit' => ['Tomatoes', 'Onions', 'Ripe plantain', 'Cassava', 'Yam', 'Fresh pepper', 'Ginger', 'Garlic'],
            'Oils & spices' => ['Palm oil', 'Vegetable oil'],
            'Dairy & eggs' => ['Eggs'],
        ];
        $supplierFor = ['Meat & fish' => 'Accra Fresh Meat & Fish'];
        foreach ($categories as $category => $names) {
            Ingredient::query()->whereIn('name', $names)->get()->each(function (Ingredient $i) use ($category, $supplierFor, $models) {
                $i->forceFill(['category' => $category, 'supplier_id' => $models[$supplierFor[$category] ?? 'Makola Market Traders']->id])->saveQuietly();
            });
        }
        Ingredient::query()->where('name', 'Long-grain rice')->update(['pack_name' => 'Bag', 'pack_quantity' => 25]);
        Ingredient::query()->where('name', 'Eggs')->update(['pack_name' => 'Crate', 'pack_quantity' => 30]);

        // Bottled drinks are counted one by one and sell out by themselves at zero.
        $quantities = ['Club Beer' => 48, 'Malta Guinness' => 36, 'Coca-Cola' => 72, 'Bottled Water' => 120, 'Sobolo' => 0];
        foreach (array_keys($quantities) as $name) {
            Item::query()->where('name', $name)->where('track_stock', false)->update(['track_stock' => true]);
        }
        $tracked = 0;
        foreach (Item::query()->where('track_stock', true)->get() as $item) {
            if ((float) $item->stock_qty == 0.0 && $item->name !== 'Sobolo') {
                $item->forceFill(['stock_qty' => $quantities[$item->name] ?? 24, 'stock_reorder_level' => 12])->saveQuietly();
            }
            if ((float) $item->fresh()->stock_qty <= 0 && ! $item->is_sold_out) {
                $item->forceFill(['is_sold_out' => true, 'sold_out_at' => now()])->saveQuietly(); // nothing left → off the menu
            }
            $tracked++;
        }

        // One delivery already received from the market, so the movements list has something to show.
        $purchaseNumber = null;
        if (! Purchase::query()->exists()) {
            $rice = Ingredient::query()->where('name', 'Long-grain rice')->first();
            $tomatoes = Ingredient::query()->where('name', 'Tomatoes')->first();
            if ($rice && $tomatoes) {
                $service = app(PurchaseService::class);
                $by = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'manager'))->first();
                $purchase = $service->create($models['Makola Market Traders'], [
                    ['ingredient_id' => $rice->id, 'quantity' => 50, 'unit_cost' => Money::fromDecimal('21.50')],
                    ['ingredient_id' => $tomatoes->id, 'quantity' => 20, 'unit_cost' => Money::fromDecimal('18')],
                ], $by, 'Weekly market run');
                $service->receive($purchase, [], $by, 'MKT-1042');
                $purchaseNumber = $purchase->displayNumber();
            }
        }

        return ['suppliers' => count($models), 'tracked_items' => $tracked, 'purchase' => $purchaseNumber];
    }
}
