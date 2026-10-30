<?php

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Seeders\IngredientsDemoSeeder;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

it('seeds twenty ingredients with units, costs and low-stock flags', function () {
    provisionTenant();

    expect((new IngredientsDemoSeeder)->run())->toBe(20)
        ->and((new IngredientsDemoSeeder)->run())->toBe(20);

    $eggs = Ingredient::where('name', 'Eggs')->first();
    expect($eggs->unit->value)->toBe('pcs')
        ->and($eggs->cost_per_unit)->toBePesewas(250)
        ->and($eggs->isLow())->toBeFalse();

    $eggs->update(['on_hand' => 10]);
    expect($eggs->fresh()->isLow())->toBeTrue()
        ->and(Ingredient::lowStock()->count())->toBe(1);
});

it('lists ingredients in the back office', function () {
    seedRoles();
    $branch = provisionTenant();
    (new IngredientsDemoSeeder)->run();
    $owner = User::factory()->forBranch($branch)->create();
    $owner->assignRole(Role::Owner->value);

    $this->actingAs($owner)->get('/admin/ingredients')->assertOk()->assertSee('Beef');
});
