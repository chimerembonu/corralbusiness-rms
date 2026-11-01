<?php

use App\Modules\Inventory\Enums\StockTakeStatus;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockTakeService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    // Someone who may count but not finish a stock take (the Inventory role may do both).
    $this->counter = User::factory()->forBranch($this->branch)->create(['name' => 'Efua']);
    $this->counter->givePermissionTo(Permission::InventoryCount->value);
    $this->rice = Ingredient::create(['name' => 'Rice', 'category' => 'Dry goods', 'unit' => 'kg', 'cost_per_unit' => 2000, 'on_hand' => 10]);
    $this->beef = Ingredient::create(['name' => 'Beef', 'category' => 'Meat & fish', 'unit' => 'kg', 'cost_per_unit' => 7000, 'on_hand' => 4]);
    $this->menu['coke']->forceFill(['track_stock' => true, 'stock_qty' => 24])->save();
    $this->service = app(StockTakeService::class);
});

it('counts everything, posts the differences and values them', function () {
    expect($this->service->categories())->toBe(['Dry goods', 'Meat & fish', 'Menu items (counted)']);

    $take = $this->service->start($this->counter);
    expect($take->displayNumber())->toBe('ST0001')->and($take->lines)->toHaveCount(3)
        ->and($take->lines->pluck('name')->all())->toBe(['Rice', 'Beef', 'Coca-Cola'])
        ->and((float) $take->lines[2]->expected)->toBe(24.0);
    expect(fn () => $this->service->start($this->counter))->toThrow(InventoryException::class, 'already open');

    [$rice, $beef, $coke] = $take->lines;
    $this->service->count($rice, 9.5, $this->counter);   // 0.5 kg short = −10.00
    $this->service->count($coke, 20, $this->counter);     // 4 bottles short (no cost)
    expect(fn () => $this->service->count($beef, -1, $this->counter))->toThrow(InventoryException::class);
    expect($rice->fresh()->difference())->toBe(-0.5)->and($rice->fresh()->differenceValue())->toBe(-1000)->and($rice->fresh()->counted_by_id)->toBe($this->counter->id);

    expect(fn () => $this->service->complete($take, $this->counter))->toThrow(ManagerPinRequiredException::class);
    $done = $this->service->complete($take, $this->manager, 'Monthly count');

    expect($done->status)->toBe(StockTakeStatus::Completed)
        ->and($done->variance_value)->toBe(-1000)
        ->and((float) $this->rice->fresh()->on_hand)->toBe(9.5)
        ->and((float) $this->beef->fresh()->on_hand)->toBe(4.0)       // not counted → untouched
        ->and((float) $this->menu['coke']->fresh()->stock_qty)->toBe(20.0)
        ->and(StockMovement::query()->where('type', 'stock_take')->count())->toBe(2)
        ->and(StockMovement::query()->where('ingredient_id', $this->rice->id)->first()->reason)->toBe('Stock take ST0001');
    expect(fn () => $this->service->count($rice, 1, $this->counter))->toThrow(InventoryException::class, 'already finished');

    // A category-only count, then cancelled.
    $meat = $this->service->start($this->manager, 'Meat & fish');
    expect($meat->lines->pluck('name')->all())->toBe(['Beef']);
    $this->service->cancel($meat, $this->manager);
    expect($meat->fresh()->status)->toBe(StockTakeStatus::Cancelled)->and($this->service->open())->toHaveCount(0);
});
