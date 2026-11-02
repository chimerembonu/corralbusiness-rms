<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\TableService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;

/*
 * Re-review: lines moved to a fresh order by split or merge lost the "bill printed" protection (H5), a
 * partly paid order could be split, merged or cancelled with its payment left dangling, and a real
 * table could be merged into a practice one.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->t1 = DiningTable::create(['name' => 'T1', 'seats' => 2]);
    $this->t2 = DiningTable::create(['name' => 'T2', 'seats' => 4]);
    $this->orders = app(OrderService::class);
    $this->tables = app(TableService::class);
});

it('keeps the bill-printed rule on lines split off or merged in', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $a = $this->orders->addItem($order, $this->menu['coke']);
    $this->orders->addItem($order, $this->menu['water']);
    $this->orders->markBillPrinted($order, $this->cashier);

    $split = $this->orders->split($order, [$a->id], $this->cashier);
    expect($split->bill_printed_at)->not->toBeNull();
    expect(fn () => $this->orders->removeItem($split, $a->fresh(), $this->cashier))->toThrow(ManagerPinRequiredException::class)
        ->and(fn () => $this->orders->void($split, 'Oops', $this->cashier))->toThrow(ManagerPinRequiredException::class);

    $billed = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1, 2);
    $this->orders->addItem($billed, $this->menu['coke']);
    $this->orders->markBillPrinted($billed, $this->cashier);
    $plain = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2, 2);
    $this->orders->addItem($plain, $this->menu['water']);

    $merged = $this->tables->merge($this->t1->fresh(), $this->t2->fresh(), $this->cashier);
    expect($merged->id)->toBe($plain->id)->and($merged->bill_printed_at)->not->toBeNull();
});

it('refuses to split, merge or cancel an order that has a payment on it', function () {
    $order = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1, 2);
    $a = $this->orders->addItem($order, $this->menu['coke'], quantity: 2); // 24.00
    $this->orders->addItem($order, $this->menu['water']);                 // 5.00
    app(PaymentService::class)->payCard($order, Money::fromDecimal('10'), null, $this->cashier);
    $other = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2, 2);
    $this->orders->addItem($other, $this->menu['water']);

    expect(fn () => $this->orders->split($order->fresh(), [$a->id], $this->cashier))->toThrow(OrderLockedException::class, 'payment')
        ->and(fn () => $this->tables->merge($this->t1->fresh(), $this->t2->fresh(), $this->cashier))->toThrow(OrderLockedException::class, 'payment')
        ->and(fn () => $this->tables->merge($this->t2->fresh(), $this->t1->fresh(), $this->cashier))->toThrow(OrderLockedException::class, 'payment')
        ->and(fn () => $this->orders->void($order->fresh(), 'Changed mind', $this->manager))->toThrow(OrderLockedException::class, 'payment');
    expect($order->fresh()->paid_total)->toBePesewas(1000)->and($order->fresh()->activeItems()->count())->toBe(2);
});

it('refuses to merge a practice table with a real one', function () {
    app(TrainingMode::class)->set(true);
    $practice = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t1, 2);
    $this->orders->addItem($practice, $this->menu['coke']);
    app(TrainingMode::class)->set(false);
    $real = $this->orders->open(OrderType::DineIn, $this->cashier, $this->t2, 2);
    $this->orders->addItem($real, $this->menu['water']);

    expect(fn () => $this->tables->merge($this->t1->fresh(), $this->t2->fresh(), $this->cashier))->toThrow(OrderLockedException::class, 'practice');
});
