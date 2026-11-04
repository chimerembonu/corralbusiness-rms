<?php

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;

/*
 * Security review M1: the 10 % threshold used to be checked per discount, so 10 % on a line plus
 * 10 % on the order (19 % in all) never asked for a manager. Now every discount is judged together
 * with what is already off the order, and a line discount remembers which manager approved it.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);

    // 2 × Coca-Cola (20.00) + water (5.00) = 25.00 subtotal; threshold is 10 % by default.
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->coke = $this->orders->addItem($this->order, $this->menu['coke'], quantity: 2);
    $this->water = $this->orders->addItem($this->order, $this->menu['water']);
});

it('refuses a line discount stacked on an order discount without a manager', function () {
    $this->orders->applyDiscount($this->order, DiscountType::Percent, 1000, 'Loyal customer', $this->cashier);

    // 10 % on the coke line (2.00) on top of 10 % on the order: 4.30 of 25.00 = 17 %.
    expect(fn () => $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 1000, 'Complaint', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect($this->coke->fresh()->hasItemDiscount())->toBeFalse();

    $line = $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 1000, 'Complaint', $this->cashier, $this->manager);
    expect($line->item_discount_approved_by_id)->toBe($this->manager->id)
        ->and($line->itemDiscountApprovedBy->name)->toBe('Akosua')
        ->and($this->order->fresh()->discount_total)->toBePesewas(430); // 2.00 + 10 % of 23.00
});

it('refuses an order discount stacked on line discounts without a manager', function () {
    $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 1000, 'Complaint', $this->cashier);
    $this->orders->applyItemDiscount($this->order, $this->water, DiscountType::Fixed, 50, 'Complaint', $this->cashier);
    expect($this->order->fresh()->discount_total)->toBePesewas(250); // exactly 10 %: still allowed

    expect(fn () => $this->orders->applyDiscount($this->order, DiscountType::Percent, 500, 'Loyal customer', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->orders->applyDiscount($this->order, DiscountType::Fixed, 100, 'Loyal customer', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect($this->order->fresh()->discount_type)->toBeNull();

    $this->orders->applyDiscount($this->order, DiscountType::Percent, 500, 'Loyal customer', $this->cashier, $this->manager);
    expect($this->order->fresh()->discount_approved_by_id)->toBe($this->manager->id);
});

it('still lets a waiter give a single discount under the threshold, with no approver recorded', function () {
    $line = $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 500, 'Complaint', $this->cashier);
    expect($line->item_discount_approved_by_id)->toBeNull();

    // Replacing the same line's discount is judged without counting the old one twice.
    $line = $this->orders->applyItemDiscount($this->order, $this->coke, DiscountType::Percent, 1000, 'Complaint', $this->cashier);
    expect($line->item_discount_value)->toBe(1000)
        ->and($this->order->fresh()->discount_total)->toBePesewas(200);

    // Removing it clears the approver too.
    $line = $this->orders->applyItemDiscount($this->order, $this->coke, null, 0, null, $this->cashier);
    expect($line->hasItemDiscount())->toBeFalse()->and($line->item_discount_approved_by_id)->toBeNull();
});
