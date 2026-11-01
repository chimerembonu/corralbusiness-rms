<?php

use App\Modules\Inventory\Livewire\StockTakeScreen;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Livewire\Livewire;

it('counts stock on a phone and finishes with a manager PIN', function () {
    seedRoles();
    $branch = provisionTenant();
    $counter = User::factory()->forBranch($branch)->withPin('5001')->create(['name' => 'Efua']);
    $counter->givePermissionTo([Permission::PosAccess->value, Permission::InventoryCount->value]); // may count, may not start or finish
    $manager = User::factory()->forBranch($branch)->withPin('2468')->create(['name' => 'Akosua']);
    $manager->assignRole(Role::Manager->value);
    $rice = Ingredient::create(['name' => 'Rice', 'category' => 'Dry goods', 'unit' => 'kg', 'on_hand' => 10]);
    Ingredient::create(['name' => 'Beef', 'category' => 'Meat & fish', 'unit' => 'kg', 'on_hand' => 4]);

    $this->actingAs($counter);
    $this->get('/pos')->assertOk()->assertSee('Stock');
    $this->get('/pos/stock-take')->assertOk()->assertSee('No stock take is open')->assertDontSee('Start: count everything'); // only managers start

    $this->actingAs($manager);
    $screen = Livewire::test(StockTakeScreen::class)
        ->assertSee('Start: count everything')->assertSee('Start: Dry goods')
        ->call('start', 'Dry goods')
        ->assertSee('ST0001')->assertSee('Rice')->assertDontSee('Beef')->assertSee('1 left to count');

    $take = StockTake::query()->first();
    $line = $take->lines->first();
    $screen->assertSee('Not counted')
        ->call('bump', $line->id, 1)->assertSee('Everything counted')->assertSee('-9') // one bottle counted, nine short
        ->set('counts.'.$line->id, '9')->call('save', $line->id)
        ->assertSee('Everything counted')->assertSee('-1');
    expect((float) $line->fresh()->counted)->toBe(9.0);

    $screen->call('finish')->assertSet('approval.permission', 'inventory.manage')
        ->call('approve', '5001')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')->assertSee('ST0001 completed');
    expect((float) $rice->fresh()->on_hand)->toBe(9.0);
});
