<?php

use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\ItemAdded;
use App\Modules\Orders\Events\ItemVoided;
use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Outbox\OutboxEvent;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
});

it('numbers orders per branch per day and stamps the business date', function () {
    $first = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $second = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $other = Branch::create(['tenant_id' => $this->branch->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);
    Tenancy::set($this->branch->tenant, $other);
    $elsewhere = $this->orders->open(OrderType::Takeaway, $this->cashier);

    expect([$first->number, $second->number, $elsewhere->number])->toBe([1, 2, 1])
        ->and($first->displayNumber())->toBe('0001')
        ->and($first->business_date->toDateString())->toBe(now()->toDateString())
        ->and($elsewhere->branch_id)->toBe($other->id);

    Tenancy::set($this->branch->tenant, $this->branch);
});

it('opens a dine-in order on a free table and refuses an occupied one', function () {
    $table = DiningTable::create(['name' => 'T1', 'seats' => 4]);
    $order = $this->orders->open(OrderType::DineIn, $this->cashier, $table, 3);

    expect($order->table_id)->toBe($table->id)
        ->and($order->covers)->toBe(3)
        ->and($table->fresh()->isOccupied())->toBeTrue()
        ->and($table->fresh()->currentOrder->id)->toBe($order->id);

    expect(fn () => $this->orders->open(OrderType::DineIn, $this->cashier, $table->fresh()))->toThrow(OrderLockedException::class);
});

it('sends pending lines to the kitchen and locks them', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $coke = $this->orders->addItem($order, $this->menu['coke']);

    Event::fake([OrderSentToKitchen::class]);
    $this->orders->sendToKitchen($order, $this->cashier);
    Event::assertDispatched(OrderSentToKitchen::class, fn ($e) => $e->items->count() === 1);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and($coke->fresh()->status)->toBe(OrderItemStatus::Sent)
        ->and($coke->fresh()->sent_at)->not->toBeNull();

    expect(fn () => $this->orders->changeQuantity($order, $coke->fresh(), 2))->toThrow(OrderLockedException::class);
    expect(fn () => $this->orders->removeItem($order, $coke->fresh()))->toThrow(OrderLockedException::class);

    // A line added afterwards is pending and sends alone.
    $water = $this->orders->addItem($order->fresh(), $this->menu['water']);
    Event::fake([OrderSentToKitchen::class]);
    $this->orders->sendToKitchen($order->fresh());
    Event::assertDispatched(OrderSentToKitchen::class, fn ($e) => $e->items->count() === 1 && $e->items->first()->id === $water->id);
});

it('needs a manager to void a sent line, and excludes it from the totals', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $coke = $this->orders->addItem($order, $this->menu['coke'], null, 2);
    $water = $this->orders->addItem($order, $this->menu['water']);
    $this->orders->sendToKitchen($order);

    expect(fn () => $this->orders->voidItem($order->fresh(), $coke->fresh(), 'Customer changed mind', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);

    Event::fake([ItemVoided::class]);
    $this->orders->voidItem($order->fresh(), $coke->fresh(), 'Customer changed mind', $this->manager, $this->cashier);
    Event::assertDispatched(ItemVoided::class);

    expect($coke->fresh()->status)->toBe(OrderItemStatus::Voided)
        ->and($coke->fresh()->voided_by_id)->toBe($this->manager->id)
        ->and($order->fresh()->total)->toBePesewas(500);
});

it('lets a cashier cancel an untouched order but not a sent one', function () {
    $open = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->void($open, 'Mistake', $this->cashier);
    expect($open->fresh()->status)->toBe(OrderStatus::Void);

    $sent = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($sent, $this->menu['coke']);
    $this->orders->sendToKitchen($sent);
    expect(fn () => $this->orders->void($sent->fresh(), 'Mistake', $this->cashier))->toThrow(ManagerPinRequiredException::class);
    $this->orders->void($sent->fresh(), 'Mistake', $this->manager);
    expect($sent->fresh()->status)->toBe(OrderStatus::Void);

    expect(fn () => $this->orders->addItem($sent->fresh(), $this->menu['coke']))->toThrow(OrderLockedException::class);
});

it('fires an event and an outbox row for every change', function () {
    Event::fake([OrderCreated::class, ItemAdded::class]);
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke']);
    Event::assertDispatched(OrderCreated::class, fn ($e) => $e->byUserId === $this->cashier->id);
    Event::assertDispatched(ItemAdded::class, fn ($e) => $e->item->name === 'Coca-Cola');
});

it('writes order events to the outbox with the branch', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke']);
    $this->orders->sendToKitchen($order);

    expect(OutboxEvent::pending()->pluck('event_type')->all())->toBe(['orders.created', 'orders.item_added', 'orders.sent_to_kitchen'])
        ->and(OutboxEvent::first()->branch_id)->toBe($this->branch->id)
        ->and(OutboxEvent::first()->payload['order_id'])->toBe($order->id);
});
