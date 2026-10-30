<?php

use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular'], 2, [$this->menu['chicken']->id => 1]); // 144.00
    $this->orders->addItem($this->order, $this->menu['water']); // 5.00 → total 149.00
});

it('takes cash, works out the change and marks the order paid', function () {
    Event::fake([OrderPaid::class]);

    $payment = $this->payments->payCash($this->order, Money::fromDecimal('200'), $this->cashier);

    expect($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->amount)->toBePesewas(14900)
        ->and($payment->tendered)->toBePesewas(20000)
        ->and($payment->change_given)->toBePesewas(5100)
        ->and($payment->received_by_id)->toBe($this->cashier->id);

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_total)->toBePesewas(14900)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->balanceDue())->toBePesewas(0)
        ->and($order->items->every(fn ($l) => $l->status === OrderItemStatus::Sent))->toBeTrue(); // sent with payment
    Event::assertDispatched(OrderPaid::class);
});

it('accepts part payments until the balance is covered', function () {
    $this->payments->payCash($this->order, Money::fromDecimal('100'), $this->cashier);
    expect($this->order->fresh()->status)->not->toBe(OrderStatus::Paid)
        ->and($this->order->fresh()->balanceDue())->toBePesewas(4900);

    $this->payments->payCash($this->order->fresh(), Money::fromDecimal('50'), $this->cashier);
    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->payments)->toHaveCount(2)
        ->and($order->payments->last()->change_given)->toBePesewas(100);
});

it('refuses payments on paid, cancelled or empty orders', function () {
    $this->payments->payCash($this->order, Money::fromDecimal('149'), $this->cashier);
    expect(fn () => $this->payments->payCash($this->order->fresh(), Money::fromDecimal('10')))->toThrow(PaymentException::class);

    $empty = $this->orders->open(OrderType::Takeaway, $this->cashier);
    expect(fn () => $this->payments->payCash($empty, Money::fromDecimal('10')))->toThrow(PaymentException::class);
    expect(fn () => $this->payments->payCash($this->order->fresh(), Money::zero()))->toThrow(PaymentException::class);
});

it('shows up in today\'s sales', function () {
    $this->payments->payCash($this->order, Money::fromDecimal('150'), $this->cashier);
    $this->orders->open(OrderType::DineIn, $this->cashier);

    $summary = app(DailySummaryService::class)->forDate(now()->toDateString());

    expect($summary['orders_paid'])->toBe(1)
        ->and($summary['orders_open'])->toBe(1)
        ->and($summary['gross_sales'])->toBePesewas(14900)
        ->and($summary['tax_total'])->toBePesewas(2400)
        ->and($summary['net_sales'])->toBePesewas(12500)
        ->and($summary['by_payment_method'][0]['method'])->toBe('cash')
        ->and($summary['by_payment_method'][0]['total'])->toBePesewas(14900)
        ->and($summary['top_items'][0]['name'])->toBe('Jollof Rice')
        ->and(collect($summary['tax_lines'])->firstWhere('code', 'VAT')['amount'])->toBePesewas(1800);
});
