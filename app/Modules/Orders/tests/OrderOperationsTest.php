<?php

use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Models\TicketItem;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\CourseFired;
use App\Modules\Orders\Exceptions\DiscountException;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->waiter = User::factory()->forBranch($this->branch)->create();
    $this->waiter->assignRole(Role::Waiter->value);
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value); // gives small discounts; waiters need a manager for every discount
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    $this->settings = app(SettingsService::class);

    // Takeaway: 2 × Coca-Cola (10.00) + water (5.00, tax free) = 25.00 subtotal, 20 % tax on 20.00 → 29.00
    $this->order = $this->orders->open(OrderType::Takeaway, $this->waiter);
    $this->coke = $this->orders->addItem($this->order, $this->menu['coke'], quantity: 2);
    $this->water = $this->orders->addItem($this->order, $this->menu['water']);
});

it('applies a small order discount, needs a manager above the threshold, and requires a reason', function () {
    $this->orders->applyDiscount($this->order, DiscountType::Percent, 1000, 'Loyal customer', $this->cashier);
    $order = $this->order->fresh();
    expect($order->discount_total)->toBePesewas(250)        // 10 % of 25.00
        ->and($order->tax_total)->toBePesewas(360)          // 20 % of (20.00 − 2.00)
        ->and($order->total)->toBePesewas(2610)
        ->and($order->discountLabel())->toBe('10%');

    expect(fn () => $this->orders->applyDiscount($this->order, DiscountType::Percent, 2500, 'Complaint', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->orders->applyDiscount($this->order, DiscountType::Fixed, 500, '', $this->cashier))
        ->toThrow(DiscountException::class);
    expect(fn () => $this->orders->applyDiscount($this->order, DiscountType::Fixed, 99900, 'Complaint', $this->cashier, $this->manager))
        ->toThrow(DiscountException::class);

    $this->orders->applyDiscount($this->order, DiscountType::Fixed, 1000, 'Complaint', $this->cashier, $this->manager);
    $order = $this->order->fresh();
    expect($order->discount_total)->toBePesewas(1000)
        ->and($order->discount_approved_by_id)->toBe($this->manager->id)
        ->and($order->discountLabel())->toBe('GH₵ 10.00');

    $this->orders->removeDiscount($this->order, $this->cashier);
    expect($this->order->fresh()->discount_total)->toBePesewas(0)->and($this->order->fresh()->total)->toBePesewas(2900);
});

it('discounts a single line and spreads the order discount over the rest', function () {
    $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 1000, 'Staff meal', $this->cashier);
    // 4.60 is 20 % of the remaining 23.00 → above the 10 % threshold, so a manager approves.
    $this->orders->applyDiscount($this->order, DiscountType::Fixed, 460, 'Promotion', $this->cashier, $this->manager);

    $order = $this->order->fresh();
    $coke = $this->coke->fresh();
    $water = $this->water->fresh();
    expect($coke->itemDiscount())->toBePesewas(200)                 // 10 % of 20.00
        ->and($order->discount_total)->toBePesewas(660)               // 2.00 + 4.60
        ->and($coke->discount_amount->add($water->discount_amount))->toBePesewas(660)
        ->and($coke->discount_amount)->toBePesewas(200 + 360)         // 4.60 split 18:5 → 3.60 / 1.00
        ->and($water->discount_amount)->toBePesewas(100)
        ->and($order->total)->toBePesewas(2500 - 660 + 288);          // tax 20 % of the coke net 14.40 = 2.88; water is tax free

    expect(fn () => $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 5000, 'Complaint', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
});

it('applies, rejects and releases promo codes', function () {
    $promo = PromoCode::create(['code' => 'welcome10', 'name' => 'Welcome 10 %', 'type' => 'percent', 'value' => 1000, 'max_uses' => 1, 'max_discount' => 200]);
    $expired = PromoCode::create(['code' => 'OLD', 'name' => 'Old', 'type' => 'fixed', 'value' => 500, 'ends_at' => now()->subDay()]);
    $big = PromoCode::create(['code' => 'BIG', 'name' => 'Big spender', 'type' => 'fixed', 'value' => 500, 'min_order' => 100000]);

    expect(fn () => $this->orders->applyPromoCode($this->order, 'NOPE', $this->cashier))->toThrow(DiscountException::class, 'Unknown promo code.')
        ->and(fn () => $this->orders->applyPromoCode($this->order, 'OLD', $this->cashier))->toThrow(DiscountException::class, 'expired')
        ->and(fn () => $this->orders->applyPromoCode($this->order, 'BIG', $this->cashier))->toThrow(DiscountException::class, 'at least');

    $this->orders->applyPromoCode($this->order, ' welcome10 ', $this->cashier);
    $order = $this->order->fresh();
    expect($order->promo_code)->toBe('WELCOME10')
        ->and($order->discount_total)->toBePesewas(200)              // 10 % = 2.50, capped at 2.00
        ->and($order->discountLabel())->toBe('WELCOME10 (10%)')
        ->and($promo->fresh()->uses_count)->toBe(1);

    $other = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($other, $this->menu['coke']);
    expect(fn () => $this->orders->applyPromoCode($other, 'WELCOME10', $this->cashier))->toThrow(DiscountException::class, 'used up');

    $this->orders->applyDiscount($this->order, DiscountType::Percent, 500, 'Owner / manager', $this->cashier);
    expect($this->order->fresh()->promo_code)->toBeNull()->and($promo->fresh()->uses_count)->toBe(0);
});

it('adds the service charge to dine-in orders only and lets a manager waive it', function () {
    $this->settings->set(TillSettings::SERVICE_CHARGE_BP, 1000);
    $table = DiningTable::create(['name' => 'T1']);

    $dineIn = $this->orders->open(OrderType::DineIn, $this->waiter, $table, 2);
    $this->orders->addItem($dineIn, $this->menu['coke'], quantity: 2);
    $takeaway = $this->orders->open(OrderType::Takeaway, $this->waiter);
    $this->orders->addItem($takeaway, $this->menu['coke'], quantity: 2);

    expect($dineIn->fresh()->service_charge)->toBePesewas(200)      // 10 % of 20.00
        ->and($dineIn->fresh()->total)->toBePesewas(2600)              // 20.00 + 4.00 tax + 2.00
        ->and($takeaway->fresh()->service_charge)->toBePesewas(0)
        ->and($takeaway->fresh()->total)->toBePesewas(2400);

    expect(fn () => $this->orders->setServiceChargeWaived($dineIn, true, $this->waiter))->toThrow(ManagerPinRequiredException::class);
    $this->orders->setServiceChargeWaived($dineIn, true, $this->manager, $this->waiter);
    expect($dineIn->fresh()->service_charge)->toBePesewas(0)->and($dineIn->fresh()->total)->toBePesewas(2400);
});

it('holds course 2 on the kitchen ticket until it is fired', function () {
    Event::fake([CourseFired::class]);
    $this->orders->setCourse($this->order, $this->coke, 2, $this->waiter);
    $this->orders->sendToKitchen($this->order, $this->waiter);

    $ticket = Ticket::query()->where('order_id', $this->order->id)->first();
    $held = TicketItem::query()->where('order_item_id', $this->coke->id)->first();
    expect($held->is_held)->toBeTrue()->and($held->course)->toBe(2)
        ->and(TicketItem::query()->where('order_item_id', $this->water->id)->value('is_held'))->toBeFalse()
        ->and(fn () => $this->orders->setCourse($this->order, $this->coke->fresh(), 3))->toThrow(OrderLockedException::class)
        ->and(fn () => $this->orders->fireCourse($this->order, 3, $this->waiter))->toThrow(OrderLockedException::class);

    $fired = $this->orders->fireCourse($this->order, 2, $this->waiter);
    Event::assertDispatched(CourseFired::class, fn ($e) => $e->course === 2 && $e->items->count() === 1);
    expect($fired->first()->id)->toBe($this->coke->id)->and($this->coke->fresh()->fired_at)->not->toBeNull();

    // Without the fake, the kitchen listener releases the line.
    Event::fake([]);
    app(KitchenService::class)->fireLines([$this->coke->id]);
    expect($held->fresh()->is_held)->toBeFalse();
});

it('splits a bill by items and by seats onto new orders', function () {
    $table = DiningTable::create(['name' => 'T2']);
    $order = $this->orders->open(OrderType::DineIn, $this->waiter, $table, 3);
    $a = $this->orders->addItem($order, $this->menu['coke'], seat: 1);
    $b = $this->orders->addItem($order, $this->menu['coke'], seat: 2);
    $c = $this->orders->addItem($order, $this->menu['water'], seat: 3);
    $this->orders->sendToKitchen($order, $this->waiter);

    expect(fn () => $this->orders->split($order, [], $this->waiter))->toThrow(OrderLockedException::class)
        ->and(fn () => $this->orders->split($order, [$a->id, $b->id, $c->id], $this->waiter))->toThrow(OrderLockedException::class);

    $seatTwo = $this->orders->splitBySeats($order, [2], $this->waiter);
    expect($seatTwo->split_from_id)->toBe($order->id)
        ->and($seatTwo->table_id)->toBe($table->id)
        ->and($seatTwo->status)->toBe(OrderStatus::Sent)
        ->and($seatTwo->items)->toHaveCount(1)
        ->and($seatTwo->total)->toBePesewas(1200)
        ->and($seatTwo->covers)->toBe(1)
        ->and($order->fresh()->covers)->toBe(2)
        ->and($order->fresh()->total)->toBePesewas(1700)          // coke 12.00 + water 5.00
        ->and($order->fresh()->number)->not->toBe($seatTwo->number);
    expect(OutboxEvent::query()->where('event_type', 'orders.split')->count())->toBe(1);
});

it('holds an order with a name and recalls it', function () {
    $this->orders->hold($this->order, 'Kofi', $this->waiter);
    expect($this->order->fresh()->isHeld())->toBeTrue()->and($this->order->fresh()->customer_name)->toBe('Kofi');

    $this->orders->recall($this->order, $this->waiter);
    expect($this->order->fresh()->isHeld())->toBeFalse()->and($this->order->fresh()->customer_name)->toBe('Kofi');
});

it('voids a sent line with a reason only when a manager approves', function () {
    $this->orders->sendToKitchen($this->order, $this->waiter);
    expect(fn () => $this->orders->voidItem($this->order, $this->coke, 'Kitchen mistake', $this->waiter))->toThrow(ManagerPinRequiredException::class);

    $this->orders->voidItem($this->order, $this->coke, 'Kitchen mistake', $this->manager, $this->waiter);
    expect($this->coke->fresh()->isVoided())->toBeTrue()
        ->and($this->coke->fresh()->void_reason)->toBe('Kitchen mistake')
        ->and($this->coke->fresh()->voided_by_id)->toBe($this->manager->id)
        ->and($this->order->fresh()->total)->toBePesewas(500);
});
