<?php

use App\Modules\Customers\Enums\AccountTransactionType;
use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Models\AccountTransaction;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;

/*
 * Re-review: negative amounts or tips were stopped only by the database; the delivery fee of a paid
 * order could still be changed; a practice order could be put on a real customer's account; and
 * refunding an on-account payment left the customer still owing it.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke'], quantity: 2); // 24.00
});

it('refuses negative amounts and tips', function () {
    expect(fn () => $this->payments->payCard($this->order, Money::of(-500), null, $this->cashier, Money::of(1000)))->toThrow(PaymentException::class)
        ->and(fn () => $this->payments->payCash($this->order, Money::of(10000), $this->cashier, Money::of(-5000)))->toThrow(PaymentException::class)
        ->and(fn () => $this->payments->payCard($this->order, Money::of(1000), null, $this->cashier, Money::of(-100)))->toThrow(PaymentException::class);
    expect(Payment::query()->count())->toBe(0);
});

it('locks the delivery details and fee once the order is paid', function () {
    $delivery = app(DeliveryService::class);
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    $delivery->setDetails($order, 'Ama', '024', 'Osu', null, null, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke']);
    $this->payments->payCash($order->fresh(), Money::fromDecimal('12'), $this->cashier);

    expect(fn () => $delivery->setDetails($order->fresh(), 'Ama', '024', 'Osu', null, Money::fromDecimal('50'), $this->manager))->toThrow(OrderLockedException::class);
    expect($order->fresh()->total)->toBePesewas(1200);
});

it('keeps practice orders off customer accounts and credits the account when an on-account payment is refunded', function () {
    $customer = Customer::create(['name' => 'Ghost Ltd', 'phone' => '020', 'allow_account' => true]);
    $accounts = app(AccountService::class);

    app(TrainingMode::class)->set(true);
    $practice = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($practice, $this->menu['coke']);
    $practice->forceFill(['customer_id' => $customer->id])->save();
    app(TrainingMode::class)->set(false);
    expect(fn () => $accounts->charge($practice->fresh(), null, $this->cashier))->toThrow(CustomerException::class, 'practice');
    expect($customer->fresh()->balance())->toBePesewas(0);

    $this->order->forceFill(['customer_id' => $customer->id])->save();
    $payment = $accounts->charge($this->order->fresh(), null, $this->cashier);
    expect($customer->fresh()->balance())->toBePesewas(2400);

    $this->payments->refund($payment, 'Wrong customer', $this->manager, $this->cashier);
    expect($customer->fresh()->balance())->toBePesewas(0)
        ->and(AccountTransaction::query()->where('customer_id', $customer->id)->where('type', AccountTransactionType::Adjustment->value)->sole()->amount)->toBe(-2400);
});
