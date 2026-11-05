<?php

use App\Modules\Inventory\Enums\CostChangeSource;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\IngredientCostChange;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\CostService;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Settings\Services\Import\Importers;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Complete Edition E5.2 — correct stock value: weighted-average cost (IAS 2), the purchase price history,
 * and a reason for every cost change made by hand.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->costs = app(CostService::class);
    $this->stock = app(StockService::class);
    $this->rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2000, 'on_hand' => 10]); // 10 kg at GH₵ 20.00
});

it('averages the stock on the shelf with each delivery, by quantity', function () {
    expect(CostService::average(10, Money::of(2000), 30, Money::of(2400))->amount)->toBe(2300)   // (200 + 720) ÷ 40
        ->and(CostService::average(0, Money::of(2000), 5, Money::of(2500))->amount)->toBe(2500)     // nothing left: the price paid
        ->and(CostService::average(-3, Money::of(2000), 5, Money::of(2500))->amount)->toBe(2500);   // below zero counts as nothing

    $supplier = Supplier::create(['name' => 'Makola Wholesale']);
    $purchases = app(PurchaseService::class);
    $order = $purchases->create($supplier, [['ingredient_id' => $this->rice->id, 'quantity' => 30, 'unit_cost' => 2400]], $this->manager);
    $purchases->receive($order, by: $this->manager);
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2300)
        ->and($this->stock->valuation())->toBePesewas(40 * 2300);

    // Sales and waste take stock out at the average and do not change it.
    $this->stock->waste($this->rice->fresh(), 4, $this->manager, 'Spoiled');
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2300)
        ->and($this->stock->movements($this->rice)->first()->unit_cost)->toBePesewas(2300);

    // A second delivery averages again: 36 kg at 23.00 + 4 kg at 20.00.
    $this->stock->move($this->rice->fresh(), StockMovementType::Purchase, 4, $this->manager, 'Market run', null, Money::of(2000));
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2270); // (828 + 80) ÷ 40 = 22.70

    // Price history: newest first, with what was paid, how much, and from which delivery.
    $history = $this->costs->history($this->rice);
    expect($history)->toHaveCount(2)
        ->and($history->first()->source)->toBe(CostChangeSource::Purchase)
        ->and($history->first()->price_paid)->toBePesewas(2000)
        ->and((float) $history->first()->on_hand_before)->toBe(36.0)
        ->and($history->last()->purchase_id)->toBe($order->id)
        ->and($history->last()->old_cost)->toBePesewas(2000)->and($history->last()->new_cost)->toBePesewas(2300)
        ->and($this->costs->lastPricePaid($this->rice))->toBePesewas(2000);
});

it('needs a reason to change a cost by hand, and keeps the change', function () {
    expect(fn () => $this->costs->change($this->rice, Money::of(2100), $this->manager, ''))->toThrow(InventoryException::class, 'reason')
        ->and(fn () => $this->costs->change($this->rice, Money::of(2000), $this->manager, 'Same'))->toThrow(InventoryException::class, 'already the cost');

    $this->costs->change($this->rice, Money::of(2100), $this->manager, 'Wrong price on the last invoice');

    $change = IngredientCostChange::query()->sole();
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2100)
        ->and($change->source)->toBe(CostChangeSource::Manual)
        ->and($change->reason)->toBe('Wrong price on the last invoice')
        ->and($change->user_id)->toBe($this->manager->id)
        ->and($change->old_cost)->toBePesewas(2000)->and($change->branch_id)->toBe($this->branch->id);
});

it('lets managers change the cost only through "Change cost", and shows the price history', function () {
    $this->actingAs($this->manager);

    // The edit form no longer changes the cost (it is not saved even if sent).
    Livewire::test(EditIngredient::class, ['record' => $this->rice->getRouteKey()])
        ->assertFormFieldIsDisabled('cost_per_unit')
        ->fillForm(['name' => 'Long-grain rice', 'cost_per_unit' => '99.00'])->call('save')->assertHasNoFormErrors();
    expect($this->rice->fresh()->name)->toBe('Long-grain rice')->and($this->rice->fresh()->cost_per_unit)->toBePesewas(2000);

    Livewire::test(ListIngredients::class)
        ->callAction(TestAction::make('changeCost')->table($this->rice), ['new_cost' => '21.50', 'reason' => ''])->assertHasActionErrors(['reason']);
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2000); // refused without a reason
    Livewire::test(ListIngredients::class)
        ->callAction(TestAction::make('changeCost')->table($this->rice), ['new_cost' => '21.50', 'reason' => 'Supplier price list'])->assertHasNoActionErrors();
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2150);

    Livewire::test(ListIngredients::class)->mountAction(TestAction::make('priceHistory')->table($this->rice))->assertActionMounted(TestAction::make('priceHistory')->table($this->rice));
    $html = view('inventory::filament.price-history', ['ingredient' => $this->rice->fresh(), 'changes' => $this->costs->history($this->rice), 'last' => null])->render();
    expect($html)->toContain('Average cost now: GH₵ 21.50 per kg')->toContain('Changed by hand')->toContain('Supplier price list')->toContain('Akosua');

    // A new ingredient starts its history with the cost it was created with.
    Livewire::test(CreateIngredient::class)
        ->fillForm(['name' => 'Palm oil', 'unit' => 'l', 'cost_per_unit' => '32.00', 'on_hand' => 5])->call('create')->assertHasNoFormErrors();
    $oil = Ingredient::query()->where('name', 'Palm oil')->sole();
    expect(IngredientCostChange::query()->where('ingredient_id', $oil->id)->sole()->source)->toBe(CostChangeSource::Opening);
});

it('suggests the last price paid on a new purchase order', function () {
    $this->stock->move($this->rice, StockMovementType::Purchase, 10, $this->manager, 'Market run', null, Money::of(2600));
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2300) // average of 20.00 and 26.00
        ->and($this->costs->lastPricePaid($this->rice))->toBePesewas(2600)
        ->and($this->costs->lastPricePaid(Ingredient::create(['name' => 'Salt', 'unit' => 'kg'])))->toBeNull();
});

it('keeps cost changes made by a spreadsheet import in the price history', function () {
    $csv = implode("\n", [
        'Name,Code,Category,Unit,Pack name,Pack quantity,Cost per unit,In stock,Reorder level,Supplier',
        'Rice,,,kg,,,24.00,,,',          // existing: cost changed 20.00 → 24.00
        'Sugar,,,kg,,,16.00,25,8,',     // new: starts its history
    ]);
    $file = tempnam(sys_get_temp_dir(), 'rms').'.csv';
    file_put_contents($file, $csv);

    Importers::import('ingredients', $file, $this->manager);

    $rice = IngredientCostChange::query()->where('ingredient_id', $this->rice->id)->sole();
    $sugar = Ingredient::query()->where('name', 'Sugar')->sole();
    expect($rice->source)->toBe(CostChangeSource::Import)->and($rice->old_cost)->toBePesewas(2000)->and($rice->new_cost)->toBePesewas(2400)
        ->and($rice->reason)->toBe('Imported from a spreadsheet')
        ->and(IngredientCostChange::query()->where('ingredient_id', $sugar->id)->sole()->source)->toBe(CostChangeSource::Import);
    @unlink($file);
});
