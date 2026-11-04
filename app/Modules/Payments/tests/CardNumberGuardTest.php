<?php

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Security\CardNumberGuard;

/*
 * Compliance assessment (PCI DSS): the till must never store a full card number. Card references hold
 * the slip number or last 4 digits; notes may not contain anything that is a valid card number.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->cashier, Money::of(5000), app(OrderService::class)->businessDate());
    $orders = app(OrderService::class);
    $this->order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($this->order, $this->menu['coke'], null, 1, [], null, $this->cashier);
    $this->payments = app(PaymentService::class);
});

it('recognises card numbers however they are typed', function () {
    expect(CardNumberGuard::containsCardNumber('4111111111111111'))->toBeTrue()
        ->and(CardNumberGuard::containsCardNumber('card 4111 1111 1111 1111 exp 12/28'))->toBeTrue()
        ->and(CardNumberGuard::containsCardNumber('5500-0000-0000-0004'))->toBeTrue()
        ->and(CardNumberGuard::containsCardNumber('table 4, no onions'))->toBeFalse()
        ->and(CardNumberGuard::containsCardNumber('call 0244123456'))->toBeFalse()
        ->and(CardNumberGuard::isSafeCardReference('1234'))->toBeTrue()
        ->and(CardNumberGuard::isSafeCardReference('SLIP 000123456'))->toBeTrue()
        ->and(CardNumberGuard::isSafeCardReference('4111111111111111'))->toBeFalse()
        ->and(CardNumberGuard::isSafeCardReference('4111 1111 1111 1112'))->toBeFalse(); // not Luhn-valid, still too many digits for a card reference
});

it('refuses a full card number as a card payment reference, and stores nothing', function () {
    expect(fn () => $this->payments->payCard($this->order->fresh(), $this->order->fresh()->balanceDue(), '4111 1111 1111 1111', $this->cashier))
        ->toThrow(PaymentException::class, 'Never type a card number');
    expect(Payment::query()->count())->toBe(0);

    $this->payments->payCard($this->order->fresh(), $this->order->fresh()->balanceDue(), '1111', $this->cashier);
    expect(Payment::query()->sole()->reference)->toBe('1111');
});

it('refuses a card number hidden in payment notes, for any payment method', function () {
    expect(fn () => $this->payments->record($this->order->fresh(), PaymentMethod::Cash, $this->order->fresh()->balanceDue(), $this->cashier, notes: 'customer card 4111111111111111'))
        ->toThrow(PaymentException::class);
});

it('keeps long Mobile Money transaction ids working, even ones that look like card numbers', function () {
    $id = '4111111111111111'; // passes the card-number check, but a MoMo reference is never a card
    $this->payments->payMobileMoney($this->order->fresh(), MomoProvider::cases()[0], $this->order->fresh()->balanceDue(), $id, $this->cashier);
    expect(Payment::query()->sole()->reference)->toBe($id);
});

it('refuses a card number on an account payment taken by card', function () {
    $customer = Customer::create(['name' => 'Kofi Ltd', 'phone' => '0200000001', 'allow_account' => true]);
    expect(fn () => app(AccountService::class)->receivePayment($customer, PaymentMethod::Card, Money::of(1000), $this->cashier, '4111111111111111'))
        ->toThrow(PaymentException::class);
});
