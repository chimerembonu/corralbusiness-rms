<?php

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\DiscountException;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;

/*
 * Re-review of M1: a fixed discount was judged once; shrinking the lines afterwards turned GH₵ 10 off
 * GH₵ 100 (10 %) into GH₵ 10 off GH₵ 10 (free). A promo code on top of line discounts also slipped
 * past the threshold, and two tills could both claim the last use of a code.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->waiter = User::factory()->forBranch($this->branch)->create();
    $this->waiter->assignRole(Role::Waiter->value);
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    // 10 × Coca-Cola = 100.00 net; threshold 10 %.
    $this->order = $this->orders->open(OrderType::Takeaway, $this->waiter);
    $this->coke = $this->orders->addItem($this->order, $this->menu['coke'], quantity: 10);
});

it('drops an unapproved fixed line discount when the line shrinks past the threshold', function () {
    $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Fixed, 1000, 'Complaint', $this->waiter); // 10 % of 100.00: fine
    $this->orders->changeQuantity($this->order, $this->coke->fresh(), 5, $this->waiter);                              // 10.00 off 50.00 = 20 %
    $line = $this->coke->fresh();
    expect($line->hasItemDiscount())->toBeFalse()
        ->and($this->order->fresh()->discount_total)->toBePesewas(0)
        ->and(Activity::query()->where('description', 'discount_dropped_over_threshold')->count())->toBe(1);

    // The same discount approved by a manager survives the shrink.
    $this->orders->applyItemDiscount($this->order, $line, DiscountType::Fixed, 1000, 'Complaint', $this->waiter, $this->manager);
    $this->orders->changeQuantity($this->order, $line->fresh(), 1, $this->waiter);
    expect($this->coke->fresh()->itemDiscount())->toBePesewas(1000)->and($this->coke->fresh()->line_total)->toBePesewas(0);
});

it('drops an unapproved fixed order discount when lines are removed, but not a percentage', function () {
    $water = $this->orders->addItem($this->order, $this->menu['water'], quantity: 2); // subtotal 110.00
    $this->orders->applyDiscount($this->order, DiscountType::Fixed, 1000, 'Loyal customer', $this->waiter); // 9 %: fine
    $this->orders->removeItem($this->order, $this->coke, $this->waiter); // 10.00 off 10.00
    expect($this->order->fresh()->discount_type)->toBeNull()->and($this->order->fresh()->total)->toBePesewas(1000);

    $this->orders->applyDiscount($this->order, DiscountType::Percent, 1000, 'Loyal customer', $this->waiter);
    $this->orders->changeQuantity($this->order, $water->fresh(), 1, $this->waiter);
    expect($this->order->fresh()->discount_type)->toBe(DiscountType::Percent)->and($this->order->fresh()->discount_total)->toBePesewas(50);
});

it('refuses a promo code stacked on line discounts past the threshold', function () {
    PromoCode::create(['code' => 'TEN', 'name' => 'Ten off', 'type' => 'percent', 'value' => 1000]);
    $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 900, 'Complaint', $this->waiter); // 9.00

    expect(fn () => $this->orders->applyPromoCode($this->order, 'TEN', $this->waiter))->toThrow(DiscountException::class, 'line discounts');
    expect($this->order->fresh()->promo_code)->toBeNull();

    $this->orders->applyItemDiscount($this->order, $this->coke->fresh(), null, 0, null, $this->waiter);
    $this->orders->applyPromoCode($this->order, 'TEN', $this->waiter);
    expect($this->order->fresh()->promo_code)->toBe('TEN')->and($this->order->fresh()->discount_total)->toBePesewas(1000);
});

it('never hands out more uses of a promo code than it has', function () {
    $promo = PromoCode::create(['code' => 'ONE', 'name' => 'One use', 'type' => 'percent', 'value' => 500, 'max_uses' => 1]);
    $this->orders->applyPromoCode($this->order, 'ONE', $this->waiter);

    $second = $this->orders->open(OrderType::Takeaway, $this->waiter);
    $this->orders->addItem($second, $this->menu['coke']);
    expect(fn () => $this->orders->applyPromoCode($second, 'ONE', $this->waiter))->toThrow(DiscountException::class, 'used up');

    // Even if a stale check let it through, the claim itself refuses: nothing can push uses past the maximum.
    $promo->forceFill(['uses_count' => 1])->save();
    $claimed = PromoCode::query()->whereKey($promo->id)->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses_count', '<', 'max_uses'))->increment('uses_count');
    expect($claimed)->toBe(0)->and($promo->fresh()->uses_count)->toBe(1);
});
