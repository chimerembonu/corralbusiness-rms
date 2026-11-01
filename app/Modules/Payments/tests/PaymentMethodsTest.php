<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentRefunded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
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
    $this->payments = app(PaymentService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke'], quantity: 5); // 50.00 + 10.00 tax = 60.00
});

it('takes several payments by different methods until the order is paid', function () {
    $momo = $this->payments->payMobileMoney($this->order, MomoProvider::Mtn, Money::fromDecimal('25'), ' MP240101.1234 ', $this->cashier);
    expect($momo->method)->toBe(PaymentMethod::MobileMoney)
        ->and($momo->provider)->toBe(MomoProvider::Mtn)
        ->and($momo->reference)->toBe('MP240101.1234')
        ->and($momo->methodLabel())->toBe('Mobile Money (MTN MoMo)')
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Open)         // part paid: still open
        ->and($this->order->fresh()->paid_total)->toBePesewas(2500)
        ->and($this->order->fresh()->balanceDue())->toBePesewas(3500);

    expect(fn () => $this->payments->payMobileMoney($this->order->fresh(), MomoProvider::Telecel, Money::fromDecimal('10'), '', $this->cashier))
        ->toThrow(PaymentException::class, 'reference');
    expect(fn () => $this->payments->payCard($this->order->fresh(), Money::fromDecimal('40'), null, $this->cashier))
        ->toThrow(PaymentException::class, 'more than the balance');

    $this->payments->payCard($this->order->fresh(), Money::fromDecimal('10'), '4421', $this->cashier);
    $cash = $this->payments->payCash($this->order->fresh(), Money::fromDecimal('50'), $this->cashier, tip: Money::fromDecimal('5'));

    expect($cash->amount)->toBePesewas(2500)
        ->and($cash->tip)->toBePesewas(500)
        ->and($cash->change_given)->toBePesewas(2000)              // 50 − 5 tip − 25 applied
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->fresh()->paid_total)->toBePesewas(6000)
        ->and($this->order->fresh()->tip_total)->toBePesewas(500)
        ->and($this->order->fresh()->payments)->toHaveCount(3);
});

it('refunds a payment with a manager and marks a fully refunded order', function () {
    Event::fake([PaymentRefunded::class, OrderRefunded::class]);
    $first = $this->payments->payCash($this->order, Money::fromDecimal('20'), $this->cashier);
    $second = $this->payments->payCash($this->order->fresh(), Money::fromDecimal('40'), $this->cashier);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid);

    expect(fn () => $this->payments->refund($first, 'Cold food', $this->cashier))->toThrow(ManagerPinRequiredException::class)
        ->and(fn () => $this->payments->refund($first, '', $this->manager))->toThrow(PaymentException::class);

    $this->payments->refund($first, 'Cold food', $this->manager, $this->cashier);
    $order = $this->order->fresh();
    expect($first->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($first->fresh()->refunded_by_id)->toBe($this->manager->id)
        ->and($order->status)->toBe(OrderStatus::Paid)                  // partly refunded, still a sale
        ->and($order->paid_total)->toBePesewas(4000)
        ->and($order->refunded_total)->toBePesewas(2000);
    Event::assertDispatched(PaymentRefunded::class);
    Event::assertNotDispatched(OrderRefunded::class);

    expect(fn () => $this->payments->refund($first->fresh(), 'Again', $this->manager))->toThrow(PaymentException::class, 'already');

    $this->payments->refund($second, 'Cold food', $this->manager);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Refunded)->and($this->order->fresh()->paid_total)->toBePesewas(0);
    Event::assertDispatched(OrderRefunded::class);

    $summary = app(DailySummaryService::class)->forDate(now()->toDateString());
    expect($summary['orders_refunded'])->toBe(1)->and($summary['refund_total'])->toBePesewas(6000)->and($summary['orders_paid'])->toBe(0);
});
