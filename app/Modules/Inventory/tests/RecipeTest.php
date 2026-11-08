<?php

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\Inventory\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\Inventory\Filament\Resources\Recipes\RecipeResource;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Features\FeatureService;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

/*
 * Complete Edition E5.1 — recipes: each dish and size lists its ingredients; selling it uses up stock
 * automatically, and a void puts it back.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = Tenancy::runAs($this->branch->tenant, null, function () {
        $owner = User::factory()->create(['tenant_id' => $this->branch->tenant_id, 'branch_id' => null]);
        $owner->assignRole(Role::Owner->value);

        return $owner;
    });
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    installTestLicence('business');

    $this->rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10]);   // GH₵ 22.00 per kg
    $this->oil = Ingredient::create(['name' => 'Vegetable oil', 'unit' => 'l', 'cost_per_unit' => 2800, 'on_hand' => 5]);
    $this->eggs = Ingredient::create(['name' => 'Eggs', 'unit' => 'pcs', 'cost_per_unit' => 250, 'on_hand' => 30]);

    // Jollof: every size 200 g rice, 30 ml oil, 1 egg; Large adds 100 g rice.
    $jollof = $this->menu['jollof'];
    RecipeLine::create(['item_id' => $jollof->id, 'ingredient_id' => $this->rice->id, 'quantity' => 200, 'unit' => 'g']);
    RecipeLine::create(['item_id' => $jollof->id, 'ingredient_id' => $this->oil->id, 'quantity' => 30, 'unit' => 'ml']);
    RecipeLine::create(['item_id' => $jollof->id, 'ingredient_id' => $this->eggs->id, 'quantity' => 1, 'unit' => 'pcs']);
    RecipeLine::create(['item_id' => $jollof->id, 'variant_id' => $this->menu['large']->id, 'ingredient_id' => $this->rice->id, 'quantity' => 100, 'unit' => 'g']);
});

function switchRecipesOn(object $t): void
{
    app(FeatureService::class)->set('recipes', true, $t->owner);
}

it('converts recipe amounts into the unit the ingredient is counted in', function () {
    expect(Unit::Gram->convert(200, Unit::Kilogram))->toBe(0.2)
        ->and(Unit::Kilogram->convert(0.5, Unit::Gram))->toBe(500.0)
        ->and(Unit::Millilitre->convert(30, Unit::Litre))->toBe(0.03)
        ->and(Unit::Piece->convert(2, Unit::Piece))->toBe(2.0)
        ->and(Unit::Gram->convert(1, Unit::Litre))->toBeNull()          // grams of oil: not convertible
        ->and(array_map(fn ($u) => $u->value, Unit::Kilogram->compatible()))->toBe(['kg', 'g'])
        ->and(array_map(fn ($u) => $u->value, Unit::Bottle->compatible()))->toBe(['bottle']);
});

it('does nothing until "Recipes & food cost" is switched on', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 2, [$this->menu['chicken']->id => 1], null, $this->manager);
    $this->orders->sendToKitchen($order, $this->manager);

    expect((float) $this->rice->fresh()->on_hand)->toBe(10.0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(RecipeResource::canAccess())->toBeFalse();
});

it('uses up each ingredient when a dish goes to the kitchen, by size and quantity, once only', function () {
    switchRecipesOn($this);
    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 2, [$this->menu['chicken']->id => 1], null, $this->manager);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['large'], 1, [$this->menu['chicken']->id => 1], null, $this->manager);
    $this->orders->sendToKitchen($order, $this->manager);
    $this->orders->sendToKitchen($order->fresh(), $this->manager); // sending again never deducts twice

    // Rice: 2 × 0.2 + 1 × (0.2 + 0.1) = 0.7 kg; oil: 3 × 0.03 = 0.09 L; eggs: 3.
    expect((float) $this->rice->fresh()->on_hand)->toBe(9.3)
        ->and((float) $this->oil->fresh()->on_hand)->toBe(4.91)
        ->and((float) $this->eggs->fresh()->on_hand)->toBe(27.0);

    $sales = StockMovement::query()->where('type', StockMovementType::Sale->value)->get();
    expect($sales)->toHaveCount(6) // 3 ingredients × 2 lines
        ->and($sales->firstWhere('ingredient_id', $this->rice->id)->reason)->toBe('Used by Jollof Rice on order #'.$order->displayNumber())
        ->and($sales->where('ingredient_id', $this->rice->id)->sum(fn ($m) => (float) $m->quantity))->toBe(-0.7);
});

it('puts exactly what was used back when a sent dish is voided, even if the recipe changed since', function () {
    switchRecipesOn($this);
    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    $line = $this->orders->addItem($order, $this->menu['jollof'], $this->menu['large'], 2, [$this->menu['chicken']->id => 1], null, $this->manager);
    $this->orders->sendToKitchen($order, $this->manager);
    expect((float) $this->rice->fresh()->on_hand)->toBe(9.4); // 2 × 0.3 kg

    RecipeLine::query()->where('ingredient_id', $this->rice->id)->update(['quantity' => 999]); // recipe edited afterwards
    $this->orders->voidItem($order->fresh(), $line->fresh(), 'Customer left', $this->manager, $this->manager);

    expect((float) $this->rice->fresh()->on_hand)->toBe(10.0)
        ->and((float) $this->oil->fresh()->on_hand)->toBe(5.0)
        ->and((float) $this->eggs->fresh()->on_hand)->toBe(30.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Void->value)->count())->toBe(3);
});

it('also counts a drink that has both "count stock" and a recipe, and never touches stock for practice orders', function () {
    switchRecipesOn($this);
    $coke = $this->menu['coke'];
    $coke->forceFill(['track_stock' => true, 'stock_qty' => 10])->save();
    RecipeLine::create(['item_id' => $coke->id, 'ingredient_id' => $this->eggs->id, 'quantity' => 1, 'unit' => 'pcs']); // (odd, but proves both happen)

    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    $this->orders->addItem($order, $coke, null, 2, [], null, $this->manager);
    $this->orders->sendToKitchen($order, $this->manager);
    expect((float) $coke->fresh()->stock_qty)->toBe(8.0)->and((float) $this->eggs->fresh()->on_hand)->toBe(28.0);

    $practice = $this->orders->open(OrderType::Takeaway, $this->manager);
    $practice->forceFill(['is_training' => true])->save();
    $this->orders->addItem($practice, $this->menu['jollof'], $this->menu['regular'], 5, [$this->menu['chicken']->id => 1], null, $this->manager);
    $this->orders->sendToKitchen($practice->fresh(), $this->manager);
    expect((float) $this->rice->fresh()->on_hand)->toBe(10.0);
});

it('uses each branch\'s own recipe and ingredients', function () {
    switchRecipesOn($this);
    $airport = Tenancy::runAs($this->branch->tenant, null, fn () => Branch::create(['tenant_id' => $this->branch->tenant_id, 'name' => 'Airport', 'code' => 'AIR', 'timezone' => 'Africa/Accra']));
    $airportRice = Tenancy::runAs($this->branch->tenant, $airport, function () {
        $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 50]);
        RecipeLine::create(['item_id' => $this->menu['jollof']->id, 'ingredient_id' => $rice->id, 'quantity' => 0.25, 'unit' => 'kg']);

        return $rice;
    });

    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1], null, $this->manager);
    $this->orders->sendToKitchen($order, $this->manager);

    expect((float) $this->rice->fresh()->on_hand)->toBe(9.8)         // this branch: 200 g
        ->and((float) $airportRice->fresh()->on_hand)->toBe(50.0)      // the Airport's rice is untouched
        ->and(app(RecipeService::class)->portionCost($this->menu['jollof'], $this->menu['regular']->id)->amount)->toBe(440 + 84 + 250);
});

it('lets a manager set up a recipe on the Recipes page, with the cost per portion', function () {
    $this->actingAs($this->manager);
    $this->get(RecipeResource::getUrl())->assertForbidden(); // switched off
    switchRecipesOn($this);
    RecipeLine::query()->delete();

    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->actingAs($waiter)->get(RecipeResource::getUrl())->assertForbidden();
    $this->actingAs($this->manager);

    Livewire::test(ListRecipes::class)->assertCanSeeTableRecords([$this->menu['jollof'], $this->menu['coke']])->assertSee('No recipe yet');

    Livewire::test(EditRecipe::class, ['record' => $this->menu['jollof']->getRouteKey()])
        ->set('data.recipeLines', [
            'a' => ['ingredient_id' => $this->rice->id, 'quantity' => '200', 'unit' => 'g', 'variant_id' => null],
            'b' => ['ingredient_id' => $this->oil->id, 'quantity' => '30', 'unit' => 'ml', 'variant_id' => null],
            'c' => ['ingredient_id' => $this->rice->id, 'quantity' => '100', 'unit' => 'g', 'variant_id' => $this->menu['large']->id],
        ])
        ->call('save')->assertHasNoFormErrors();

    $lines = RecipeLine::query()->where('item_id', $this->menu['jollof']->id)->get();
    expect($lines)->toHaveCount(3)->and($lines->pluck('branch_id')->unique()->all())->toBe([$this->branch->id]);

    // Regular: 0.2 kg × 22 + 0.03 L × 28 = 4.40 + 0.84; Large adds 0.1 kg × 22 = 2.20.
    expect(RecipeResource::costSummary($this->menu['jollof']->fresh()))->toBe('Large: GH₵ 7.44 · Regular: GH₵ 5.24'); // in the menu's own size order
    Livewire::test(ListRecipes::class)->assertSee('Regular: GH₵ 5.24');

    // A unit that cannot be converted is refused with a plain message.
    Livewire::test(EditRecipe::class, ['record' => $this->menu['coke']->getRouteKey()])
        ->set('data.recipeLines', ['x' => ['ingredient_id' => $this->oil->id, 'quantity' => '5', 'unit' => 'g', 'variant_id' => null]])
        ->call('save')->assertHasFormErrors();
    expect(RecipeLine::query()->where('item_id', $this->menu['coke']->id)->count())->toBe(0);
});

it('is open to whoever manages the stock, once switched on', function () {
    switchRecipesOn($this);
    $storekeeper = User::factory()->forBranch($this->branch)->create();
    $storekeeper->assignRole(Role::Inventory->value);

    $this->actingAs($storekeeper)->get(RecipeResource::getUrl())->assertOk()->assertSee('Recipes');
});
