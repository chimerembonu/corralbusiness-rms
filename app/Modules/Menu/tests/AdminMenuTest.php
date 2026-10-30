<?php

use App\Modules\Menu\Filament\Resources\Items\ItemResource;
use App\Modules\Menu\Filament\Resources\Items\Pages\EditItem;
use App\Modules\Menu\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ModifierGroup;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    Item::create(['category_id' => Category::create(['name' => 'Rice'])->id, 'name' => 'Jollof Rice', 'price' => 4500]);
    Tenancy::forget();
});

it('lets the owner open the menu screens in the back office', function () {
    $this->actingAs($this->owner);

    $this->get('/admin/items')->assertOk()->assertSee('Jollof Rice');
    $this->get('/admin/categories')->assertOk()->assertSee('Rice');
    $this->get('/admin/modifier-groups')->assertOk();
    $this->get(ItemResource::getUrl('create'))->assertOk();
});

it('keeps a waiter out of the back office', function () {
    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);

    $this->actingAs($waiter)->get('/admin/items')->assertForbidden();
});

it('only shows the logged-in restaurant\'s menu', function () {
    $other = provisionTenant('Other');
    Item::create(['category_id' => Category::create(['name' => 'Other cat'])->id, 'name' => 'Secret Dish', 'price' => 100]);
    Tenancy::forget();

    $this->actingAs($this->owner)->get('/admin/items')->assertOk()->assertSee('Jollof Rice')->assertDontSee('Secret Dish');
});

it('opens the edit screens for an item with sizes, a combo and a modifier group', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $item = Item::where('name', 'Jollof Rice')->first();
    $item->variants()->create(['name' => 'Large', 'price' => 6000]);
    $combo = Item::create(['category_id' => $item->category_id, 'type' => 'combo', 'name' => 'Lunch Combo', 'price' => 7000]);
    $combo->comboItems()->create(['item_id' => $item->id, 'quantity' => 1]);
    $group = ModifierGroup::create(['name' => 'Protein', 'min_select' => 1, 'max_select' => 1]);
    $group->modifiers()->create(['name' => 'Chicken', 'price' => 1500]);
    Tenancy::forget();

    $this->actingAs($this->owner);
    $this->get(ItemResource::getUrl('edit', ['record' => $item]))->assertOk()->assertSee('45.00')->assertSee('Sizes / variants');
    $this->get(ItemResource::getUrl('edit', ['record' => $combo]))->assertOk()->assertSee('Combo contents');
    $this->get(ModifierGroupResource::getUrl('edit', ['record' => $group]))->assertOk()->assertSee('Chicken');
});

it('saves a price typed in cedis as pesewas', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $item = Item::where('name', 'Jollof Rice')->first();
    Tenancy::forget();

    $this->actingAs($this->owner);

    Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])
        ->assertSchemaStateSet(['price' => '45.00'])
        ->fillForm(['price' => '55.50', 'name' => 'Jollof Rice (Party)'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->fresh()->price)->toBePesewas(5550)
        ->and($item->fresh()->name)->toBe('Jollof Rice (Party)');
});
