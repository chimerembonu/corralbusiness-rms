<?php

use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Enums\TableStatus;
use App\Modules\Orders\Events\OrderCoversChanged;
use App\Modules\Orders\Events\OrderTransferred;
use App\Modules\Orders\Events\TablesMerged;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Exceptions\TableUnavailableException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\TableService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->indoor = FloorSection::create(['name' => 'Indoor', 'sort_order' => 1]);
    $this->terrace = FloorSection::create(['name' => 'Terrace', 'sort_order' => 2]);
    $this->t1 = DiningTable::create(['name' => 'T1', 'seats' => 2, 'section_id' => $this->indoor->id, 'pos_x' => 0, 'pos_y' => 0]);
    $this->t2 = DiningTable::create(['name' => 'T2', 'seats' => 4, 'section_id' => $this->indoor->id, 'pos_x' => 4, 'pos_y' => 0]);
    $this->t3 = DiningTable::create(['name' => 'T3', 'seats' => 4, 'section_id' => $this->indoor->id]);
    $this->p1 = DiningTable::create(['name' => 'P1', 'seats' => 6, 'section_id' => $this->terrace->id, 'pos_x' => 0, 'pos_y' => 0]);
    $this->orders = app(OrderService::class);
    $this->tables = app(TableService::class);
});

it('groups the floor plan by section and derives each table status', function () {
    $seated = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1);
    $ordered = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2);
    $this->orders->addItem($ordered, $this->menu['coke']);
    $this->orders->sendToKitchen($ordered, $this->cashier);

    $plan = $this->tables->floorPlan();

    expect($plan)->toHaveCount(2)
        ->and($plan[0]['section']->name)->toBe('Indoor')
        ->and($plan[0]['tables']->pluck('name')->all())->toBe(['T1', 'T2', 'T3'])
        ->and($plan[1]['section']->name)->toBe('Terrace');

    $byName = $plan[0]['tables']->keyBy('name');
    expect($byName['T1']->status())->toBe(TableStatus::Seated)
        ->and($byName['T2']->status())->toBe(TableStatus::Ordered)
        ->and($byName['T3']->status())->toBe(TableStatus::Free)
        ->and($byName['T3']->isPlaced())->toBeTrue()       // auto-placed for display…
        ->and($this->t3->fresh()->isPlaced())->toBeFalse() // …but not saved
        ->and($byName['T3']->pos_x)->toBe(2)               // first free slot after T1 (0–1) in row 0
        ->and($byName['T3']->pos_y)->toBe(0);
});

it('saves dragged positions clamped to the grid', function () {
    $moved = $this->tables->arrange([
        ['id' => $this->t1->id, 'x' => 5, 'y' => 3],
        ['id' => $this->t2->id, 'x' => 99, 'y' => -4],
        ['id' => 'not-a-table', 'x' => 1, 'y' => 1],
    ]);

    expect($moved)->toBe(2)
        ->and([$this->t1->fresh()->pos_x, $this->t1->fresh()->pos_y])->toBe([5, 3])
        ->and([$this->t2->fresh()->pos_x, $this->t2->fresh()->pos_y])->toBe([DiningTable::COLS - 2, 0]);
});

it('moves a dine-in order to a free table and refuses occupied or wrong targets', function () {
    Event::fake([OrderTransferred::class]);
    $order = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1);
    $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2);

    expect(fn () => $this->tables->transfer($order, $this->t2->fresh(), $this->cashier))->toThrow(OrderLockedException::class)
        ->and(fn () => $this->tables->transfer($order, $this->t1->fresh(), $this->cashier))->toThrow(TableUnavailableException::class);

    $this->tables->transfer($order, $this->t3, $this->cashier);

    expect($order->fresh()->table_id)->toBe($this->t3->id)
        ->and($this->t1->fresh()->status())->toBe(TableStatus::Free)
        ->and($this->t3->fresh()->status())->toBe(TableStatus::Seated);
    Event::assertDispatched(OrderTransferred::class, fn ($e) => $e->fromTableId === $this->t1->id && $e->toTableId === $this->t3->id);

    $takeaway = $this->orders->open(OrderType::Takeaway, $this->cashier);
    expect(fn () => $this->tables->transfer($takeaway, $this->p1, $this->cashier))->toThrow(TableUnavailableException::class);
});

it('merges two tables with open orders into one order and frees them when paid', function () {
    $main = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2, 3);
    $this->orders->addItem($main, $this->menu['coke']);
    $this->orders->sendToKitchen($main, $this->cashier);
    $joining = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1, 2);
    $this->orders->addItem($joining, $this->menu['coke'], quantity: 2);

    $merged = $this->tables->merge($this->t1->fresh(), $this->t2->fresh(), $this->cashier);

    expect($merged->id)->toBe($main->id)
        ->and($merged->covers)->toBe(5)
        ->and($merged->items)->toHaveCount(2)
        ->and($merged->total)->toBePesewas(3600)                    // 3 × 10.00 + 20 % tax
        ->and($joining->fresh()->status)->toBe(OrderStatus::Merged)
        ->and($joining->fresh()->merged_into_id)->toBe($main->id)
        ->and($joining->fresh()->total)->toBePesewas(0)
        ->and($this->t1->fresh()->status())->toBe(TableStatus::Merged)
        ->and($this->t1->fresh()->merged_into_id)->toBe($this->t2->id)
        ->and($this->t2->fresh()->totalSeats())->toBe(6)
        ->and(OutboxEvent::query()->where('event_type', 'orders.tables_merged')->count())->toBe(1);

    // A merged table cannot take a new order or be merged again.
    expect(fn () => $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1->fresh()))->toThrow(TableUnavailableException::class)
        ->and(fn () => $this->tables->merge($this->t1->fresh(), $this->t3, $this->cashier))->toThrow(TableUnavailableException::class);

    app(PaymentService::class)->payCash($merged, Money::of(3600), $this->cashier);

    expect($this->t1->fresh()->isMerged())->toBeFalse()
        ->and($this->t1->fresh()->status())->toBe(TableStatus::Free)
        ->and($this->t2->fresh()->status())->toBe(TableStatus::Free);
});

it('joins a free table onto an occupied one, or moves the only order to the main table', function () {
    Event::fake([TablesMerged::class]);
    $order = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1, 2);

    // Joining table has the order, main table is free → the order moves to the main table.
    $result = $this->tables->merge($this->t1->fresh(), $this->t2->fresh(), $this->cashier);
    expect($result->id)->toBe($order->id)->and($result->table_id)->toBe($this->t2->id)
        ->and($this->t1->fresh()->merged_into_id)->toBe($this->t2->id);

    // Then a free table joins the party.
    $this->tables->merge($this->t3->fresh(), $this->t2->fresh(), $this->cashier);
    expect($this->t3->fresh()->merged_into_id)->toBe($this->t2->id)
        ->and($this->t2->fresh()->totalSeats())->toBe(10);

    Event::assertDispatchedTimes(TablesMerged::class, 2);

    expect(fn () => $this->tables->merge($this->p1, DiningTable::create(['name' => 'X']), $this->cashier))->toThrow(TableUnavailableException::class);
});

it('keeps kitchen tickets with the order after a move or a merge', function () {
    $main = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2);
    $this->orders->addItem($main, $this->menu['coke']);
    $this->orders->sendToKitchen($main, $this->cashier);
    $other = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1);
    $this->orders->addItem($other, $this->menu['coke']);
    $this->orders->sendToKitchen($other, $this->cashier);

    $this->tables->transfer($main, $this->t3, $this->cashier);
    expect(Ticket::query()->where('order_id', $main->id)->value('table_name'))->toBe('T3');

    $this->tables->merge($this->t1->fresh(), $this->t3->fresh(), $this->cashier);
    expect(Ticket::query()->where('order_id', $main->id)->count())->toBe(2)
        ->and(Ticket::query()->where('order_id', $other->id)->count())->toBe(0)
        ->and(Ticket::query()->where('order_id', $main->id)->pluck('table_name')->unique()->all())->toBe(['T3']);
});

it('changes the number of guests', function () {
    Event::fake([OrderCoversChanged::class]);
    $order = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1, 2);

    $this->orders->setCovers($order, 7, $this->cashier);

    expect($order->fresh()->covers)->toBe(7);
    Event::assertDispatched(OrderCoversChanged::class);
});
