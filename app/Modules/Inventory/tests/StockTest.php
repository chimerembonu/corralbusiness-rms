<?php

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxEvent;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->stock = app(StockService::class);
    $this->rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10, 'reorder_level' => 5]);
});

it('records every stock change as a movement with the level after it', function () {
    $this->stock->adjust($this->rice, 15, $this->manager, 'Count error');
    $this->stock->waste($this->rice, 2.5, $this->manager, 'Spoiled');
    expect(fn () => $this->stock->waste($this->rice, 100, $this->manager, 'Spoiled'))->toThrow(InventoryException::class, 'Not enough Rice');
    expect(fn () => $this->stock->waste($this->rice, 1, $this->manager, ''))->toThrow(InventoryException::class);
    expect(fn () => $this->stock->adjust($this->rice, 0, $this->manager, 'x'))->toThrow(InventoryException::class);
    $this->stock->setLevel($this->rice, 20, $this->manager, 'Count error');
    expect($this->stock->setLevel($this->rice, 20, $this->manager, 'Count error'))->toBeNull(); // no change, no movement

    $movements = $this->stock->movements($this->rice);
    expect((float) $this->rice->fresh()->on_hand)->toBe(20.0)
        ->and($movements)->toHaveCount(3)
        ->and($movements->pluck('type')->map->value->all())->toBe(['adjustment', 'wastage', 'adjustment'])
        ->and((float) $movements->last()->quantity)->toBe(15.0)->and((float) $movements->last()->on_hand_after)->toBe(25.0)
        ->and((float) $movements[1]->quantity)->toBe(-2.5)->and($movements[1]->reason)->toBe('Spoiled')->and($movements[1]->user_id)->toBe($this->manager->id)
        ->and(OutboxEvent::query()->where('event_type', 'inventory.stock_moved')->count())->toBe(3)
        ->and($this->rice->fresh()->stockValue())->toBe(44000)
        ->and($this->stock->valuation())->toBePesewas(44000);
});

it('counts drinks down on sale, back up on void, and sells out at zero by itself', function () {
    $coke = $this->menu['coke'];
    $coke->forceFill(['track_stock' => true, 'stock_qty' => 3, 'stock_reorder_level' => 2])->save();
    $orders = app(OrderService::class);

    $order = $orders->open(OrderType::Takeaway, $this->manager);
    $orders->addItem($order, $coke, quantity: 2);
    $orders->addItem($order, $this->menu['water']); // not counted
    expect((float) $coke->fresh()->stock_qty)->toBe(3.0); // nothing until it goes to the kitchen

    $orders->sendToKitchen($order, $this->manager);
    expect((float) $coke->fresh()->stock_qty)->toBe(1.0)
        ->and($coke->fresh()->isStockLow())->toBeTrue()
        ->and($coke->fresh()->is_sold_out)->toBeFalse()
        ->and($this->stock->lowStock()->pluck('name')->all())->toBe(['Coca-Cola']);

    $second = $orders->open(OrderType::Takeaway, $this->manager);
    $orders->addItem($second, $coke);
    $orders->sendToKitchen($second, $this->manager);
    expect((float) $coke->fresh()->stock_qty)->toBe(0.0)->and($coke->fresh()->is_sold_out)->toBeTrue();

    // Void the first order's coke line → back in stock and on sale again.
    $line = $order->items()->where('item_id', $coke->id)->first();
    $orders->voidItem($order, $line, 'Customer changed mind', $this->manager);
    expect((float) $coke->fresh()->stock_qty)->toBe(2.0)->and($coke->fresh()->is_sold_out)->toBeFalse();

    // Cancelling the second (sent) order restocks it once, even if listeners fire twice.
    $orders->void($second, 'Customer left', $this->manager);
    expect((float) $coke->fresh()->stock_qty)->toBe(3.0);
    expect(StockMovement::query()->where('item_id', $coke->id)->pluck('type')->map->value->all())->toBe(['sale', 'sale', 'void', 'void']);

    // A pending (never sent) order voided → no movement.
    $third = $orders->open(OrderType::Takeaway, $this->manager);
    $orders->addItem($third, $coke);
    $orders->void($third, 'Oops', $this->manager);
    expect((float) $coke->fresh()->stock_qty)->toBe(3.0);
});

it('records opening stock for a new ingredient and can go negative only on sales', function () {
    $movement = $this->stock->recordOpening($this->rice, $this->manager);
    expect($movement->type)->toBe(StockMovementType::Opening)->and((float) $movement->quantity)->toBe(10.0);
    expect($this->stock->recordOpening(Ingredient::create(['name' => 'Salt', 'unit' => 'kg'])))->toBeNull();

    $movement = $this->stock->move($this->rice, StockMovementType::Purchase, 5, $this->manager, 'Test', null, Money::fromDecimal('25'));
    // Average cost (E5.2): (10 × 22.00 + 5 × 25.00) ÷ 15 = 23.00; the movement keeps the price paid.
    expect($this->rice->fresh()->cost_per_unit)->toBePesewas(2300)->and($movement->unit_cost)->toBePesewas(2500);
});
