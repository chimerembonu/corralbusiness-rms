<?php

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Event;

/*
 * Re-review N5: "already refunded" was checked outside the transaction on the copy the screen held, so a
 * double tap on Refund (or a second till holding a stale copy) could refund twice and credit an account
 * customer twice. The payment row is now locked and re-read inside the transaction.
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

it('records only one refund when the same payment is refunded twice from stale copies', function () {
    Event::fake([OrderRefunded::class]);
    $payment = $this->payments->payCash($this->order, Money::fromDecimal('24'), $this->cashier);
    $staleCopy = Payment::query()->findOrFail($payment->id); // what the second tap / second till holds

    $this->payments->refund($payment, 'Cold drink', $this->manager, $this->cashier);
    expect($staleCopy->isRefunded())->toBeFalse(); // the stale copy still thinks it is open

    expect(fn () => $this->payments->refund($staleCopy, 'Cold drink', $this->manager, $this->cashier))->toThrow(PaymentException::class, 'already');

    $order = $this->order->fresh();
    expect($order->refunded_total)->toBePesewas(2400)
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and(Payment::query()->where('status', 'refunded')->count())->toBe(1);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('credits an account customer only once', function () {
    $customer = Customer::create(['name' => 'Ghost Ltd', 'phone' => '020', 'allow_account' => true]);
    $this->order->forceFill(['customer_id' => $customer->id])->save();
    $payment = app(AccountService::class)->charge($this->order->fresh(), null, $this->cashier);
    $staleCopy = Payment::query()->findOrFail($payment->id);
    expect($customer->fresh()->balance())->toBePesewas(2400);

    $this->payments->refund($payment, 'Wrong customer', $this->manager, $this->cashier);
    expect(fn () => $this->payments->refund($staleCopy, 'Wrong customer', $this->manager, $this->cashier))->toThrow(PaymentException::class, 'already');

    expect($customer->fresh()->balance())->toBePesewas(0);
});
