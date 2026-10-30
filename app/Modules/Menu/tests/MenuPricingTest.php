<?php

use App\Modules\Menu\Models\BranchPrice;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Settings\Models\Branch;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->branch = provisionTenant();
    $this->category = Category::create(['name' => 'Rice']);
    $this->jollof = Item::create(['category_id' => $this->category->id, 'name' => 'Jollof', 'code' => 'JOL', 'price' => Money::fromDecimal(45)]);
    $this->menu = app(MenuService::class);
});

it('stores prices as pesewas and reads them back as Money', function () {
    expect($this->jollof->fresh()->price)->toBePesewas(4500)
        ->and($this->jollof->fresh()->getRawOriginal('price'))->toBe(4500);
});

it('uses the default variant price as the base price', function () {
    ItemVariant::create(['item_id' => $this->jollof->id, 'name' => 'Regular', 'price' => 4500, 'is_default' => true]);
    $large = ItemVariant::create(['item_id' => $this->jollof->id, 'name' => 'Large', 'price' => 6000]);

    expect($this->menu->priceFor($this->jollof->fresh()))->toBePesewas(4500)
        ->and($this->menu->priceFor($this->jollof, $large))->toBePesewas(6000);
});

it('applies a per-branch price override for an item and a variant', function () {
    $large = ItemVariant::create(['item_id' => $this->jollof->id, 'name' => 'Large', 'price' => 6000]);
    $airport = Branch::create(['tenant_id' => $this->branch->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);

    BranchPrice::create(['branch_id' => $airport->id, 'item_id' => $this->jollof->id, 'price' => 5500]);
    BranchPrice::create(['branch_id' => $airport->id, 'item_id' => $this->jollof->id, 'variant_id' => $large->id, 'price' => 7500]);

    expect($this->menu->priceFor($this->jollof, null, $airport))->toBePesewas(5500)
        ->and($this->menu->priceFor($this->jollof, $large, $airport))->toBePesewas(7500)
        ->and($this->menu->priceFor($this->jollof, null, $this->branch))->toBePesewas(4500)
        ->and($this->menu->priceFor($this->jollof, $large, $this->branch))->toBePesewas(6000);
});

it('hides an item at a branch that does not sell it', function () {
    $airport = Branch::create(['tenant_id' => $this->branch->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);
    BranchPrice::create(['branch_id' => $airport->id, 'item_id' => $this->jollof->id, 'is_available' => false]);

    expect($this->menu->isOrderable($this->jollof, null, $airport))->toBeFalse()
        ->and($this->menu->isOrderable($this->jollof, null, $this->branch))->toBeTrue();
});

it('keeps item codes unique per restaurant', function () {
    Item::create(['category_id' => $this->category->id, 'name' => 'Other', 'code' => 'JOL', 'price' => 100]);
})->throws(QueryException::class);
