<?php

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Modules\Inventory\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Modules\Inventory\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Modules\Inventory\Filament\Resources\StockTakes\Pages\CountStockTake;
use App\Modules\Inventory\Filament\Resources\StockTakes\Pages\ListStockTakes;
use App\Modules\Inventory\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
    $this->rice = Ingredient::create(['name' => 'Rice', 'category' => 'Dry goods', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10, 'reorder_level' => 20]);
});

it('creates suppliers and ingredients, adjusts stock and records wastage from the back office', function () {
    Livewire::test(CreateSupplier::class)->fillForm(['name' => 'Makola Market Traders', 'phone' => '+233240000000', 'is_active' => true])->call('create')->assertHasNoFormErrors();
    Tenancy::set($this->branch->tenant, $this->branch);
    $supplier = Supplier::query()->first();
    expect($supplier->name)->toBe('Makola Market Traders');

    Livewire::test(CreateIngredient::class)
        ->fillForm(['name' => 'Palm oil', 'category' => 'Oils & spices', 'unit' => 'l', 'supplier_id' => $supplier->id, 'cost_per_unit' => '32', 'on_hand' => 20, 'reorder_level' => 5, 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();
    Tenancy::set($this->branch->tenant, $this->branch);
    $oil = Ingredient::query()->where('name', 'Palm oil')->first();
    expect($oil->movements()->count())->toBe(1)->and($oil->movements()->first()->type->value)->toBe('opening')->and($oil->supplier_id)->toBe($supplier->id);

    $this->get('/admin/ingredients')->assertOk()->assertSee('Rice')->assertSee('10 kg');
    Livewire::test(ListIngredients::class)
        ->callAction(TestAction::make('adjust')->table($this->rice), ['new_level' => 25, 'reason' => 'Count error'])->assertHasNoErrors()
        ->callAction(TestAction::make('waste')->table($this->rice), ['quantity' => 2, 'reason' => 'Spoiled'])->assertHasNoErrors();
    expect((float) $this->rice->fresh()->on_hand)->toBe(23.0)->and($this->rice->movements()->count())->toBe(2);

    $this->get('/admin/stock-movements')->assertOk()->assertSee('Spoiled')->assertSee('Count error');
    $this->get('/admin')->assertOk()->assertSee('Low stock')->assertSee('Nothing is low on stock');
    $this->rice->forceFill(['on_hand' => 3])->save();
    $this->get('/admin')->assertSee('Rice')->assertSee('3 kg');
});

it('creates and receives a purchase in the back office', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $supplier = Supplier::create(['name' => 'Makola']);

    Livewire::test(CreatePurchase::class)
        ->fillForm(['supplier_id' => $supplier->id, 'notes' => 'Weekly', 'lines' => [['ingredient_id' => $this->rice->id, 'quantity' => 50, 'unit_cost' => '21.50']]])
        ->call('create')->assertHasNoFormErrors();
    Tenancy::set($this->branch->tenant, $this->branch);
    $purchase = Purchase::query()->first();
    expect($purchase->status)->toBe(PurchaseStatus::Draft)->and($purchase->total)->toBePesewas(107500);

    $this->get('/admin/purchases')->assertOk()->assertSee('PO0001')->assertSee('Makola');
    $line = $purchase->lines()->first();
    Livewire::test(ListPurchases::class)
        ->callAction(TestAction::make('receive')->table($purchase), ['reference' => 'INV-9', 'received' => [$line->id => 48]])->assertHasNoErrors();
    expect($purchase->fresh()->status)->toBe(PurchaseStatus::Received)
        ->and((float) $this->rice->fresh()->on_hand)->toBe(58.0)
        ->and($this->rice->fresh()->cost_per_unit)->toBePesewas(2159) // average cost, the default: (10 kg × 22.00 + 48 kg × 21.50) ÷ 58
        ->and($purchase->fresh()->reference)->toBe('INV-9');
});

it('runs a stock take from the back office count sheet', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    Livewire::test(ListStockTakes::class)->callAction('start', ['category' => 'Dry goods'])->assertHasNoErrors();
    Tenancy::set($this->branch->tenant, $this->branch);
    $take = StockTake::query()->first();
    expect($take->lines)->toHaveCount(1);
    $line = $take->lines->first();

    Livewire::test(CountStockTake::class, ['record' => $take->id])
        ->assertSee('Rice')->assertSee('ST0001')
        ->set('counts.'.$line->id, '8.5')->call('saveCount', $line->id)
        ->assertSee('-1.5')
        ->callAction('complete')->assertHasNoErrors();
    expect($take->fresh()->status->value)->toBe('completed')->and((float) $this->rice->fresh()->on_hand)->toBe(8.5);

    $this->get('/inventory/stock-takes/'.$take->id.'/print')->assertOk()->assertSee('Count sheet')->assertSee('Rice');
    $this->get('/admin/stock-takes')->assertOk()->assertSee('ST0001')->assertSee('Completed');
});
