<?php

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Filament\Resources\ExtraRecipes\ExtraRecipeResource;
use App\Modules\Inventory\Filament\Resources\ExtraRecipes\Pages\EditExtraRecipe;
use App\Modules\Inventory\Filament\Resources\ExtraRecipes\Pages\ListExtraRecipes;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\ModifierRecipeLine;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\RecipeService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Services\FoodCostService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Features\FeatureService;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

/*
 * Recipes for extras (E5.1 follow-up): "Chicken" or "Extra plantain" chosen on a plate uses up its own
 * ingredients, on top of the dish's recipe — and its cost shows in food cost & margin.
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
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    installTestLicence('business');
    app(FeatureService::class)->set('recipes', true, $this->owner);
    $this->orders = app(OrderService::class);

    $this->rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10]);
    $this->chicken = Ingredient::create(['name' => 'Chicken', 'unit' => 'kg', 'cost_per_unit' => 4800, 'on_hand' => 5]);
    $this->plantain = Ingredient::create(['name' => 'Ripe plantain', 'unit' => 'kg', 'cost_per_unit' => 1000, 'on_hand' => 8]);
    RecipeLine::create(['item_id' => $this->menu['jollof']->id, 'ingredient_id' => $this->rice->id, 'quantity' => 200, 'unit' => 'g']);
    ModifierRecipeLine::create(['modifier_id' => $this->menu['chicken']->id, 'ingredient_id' => $this->chicken->id, 'quantity' => 150, 'unit' => 'g']);
    ModifierRecipeLine::create(['modifier_id' => $this->menu['plantain']->id, 'ingredient_id' => $this->plantain->id, 'quantity' => 100, 'unit' => 'g']);
    ModifierRecipeLine::create(['modifier_id' => $this->menu['plantain']->id, 'ingredient_id' => $this->rice->id, 'quantity' => 50, 'unit' => 'g']); // (proves dish + extra add up)
});

it('uses up each extra\'s ingredients per extra and per plate, added to the dish\'s own', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    // 3 plates of Regular Jollof, each with chicken and 2 × extra plantain.
    $line = $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 3, [$this->menu['chicken']->id => 1, $this->menu['plantain']->id => 2], null, $this->manager);

    expect(app(RecipeService::class)->usageForLine($line))->toEqual([
        $this->rice->id => 0.9,       // 3 × (0.2 dish + 2 × 0.05 extra)
        $this->chicken->id => 0.45,   // 3 × 0.15
        $this->plantain->id => 0.6,   // 3 × 2 × 0.1
    ]);

    $this->orders->sendToKitchen($order, $this->manager);
    expect((float) $this->rice->fresh()->on_hand)->toBe(9.1)
        ->and((float) $this->chicken->fresh()->on_hand)->toBe(4.55)
        ->and((float) $this->plantain->fresh()->on_hand)->toBe(7.4)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale->value)->count())->toBe(3); // one per ingredient

    // A void puts all of it back.
    $this->orders->voidItem($order->fresh(), $line->fresh(), 'Customer left', $this->manager, $this->manager);
    expect((float) $this->rice->fresh()->on_hand)->toBe(10.0)
        ->and((float) $this->chicken->fresh()->on_hand)->toBe(5.0)
        ->and((float) $this->plantain->fresh()->on_hand)->toBe(8.0);
});

it('counts the extras in food cost & margin', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->manager);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1], null, $this->manager);
    $order = $order->fresh();
    app(PaymentService::class)->payCash($order, $order->balanceDue(), $this->manager);

    $day = today()->toDateString();
    $jollof = collect(app(FoodCostService::class)->report($day, $day)['dishes'])->firstWhere('name', 'Jollof Rice (Regular)');
    expect($jollof['cost'])->toBe(440 + 720); // 0.2 kg rice × 22.00 + 0.15 kg chicken × 48.00
});

it('lets a manager set up an extra\'s recipe, with its cost', function () {
    ModifierRecipeLine::query()->delete();
    $this->actingAs($this->manager);

    Livewire::test(ListExtraRecipes::class)->assertCanSeeTableRecords([$this->menu['chicken'], $this->menu['plantain']])->assertSee('No recipe yet');

    Livewire::test(EditExtraRecipe::class, ['record' => $this->menu['chicken']->getRouteKey()])
        ->set('data.recipeLines', ['a' => ['ingredient_id' => $this->chicken->id, 'quantity' => '150', 'unit' => 'g']])
        ->call('save')->assertHasNoFormErrors();

    $line = ModifierRecipeLine::query()->sole();
    expect($line->branch_id)->toBe($this->branch->id)->and($line->modifier_id)->toBe($this->menu['chicken']->id)
        ->and(app(RecipeService::class)->modifierCost($this->menu['chicken'])->amount)->toBe(720);
    Livewire::test(ListExtraRecipes::class)->assertSee('GH₵ 7.20');

    app(FeatureService::class)->set('recipes', false, $this->owner);
    $this->get(ExtraRecipeResource::getUrl())->assertForbidden();
});
