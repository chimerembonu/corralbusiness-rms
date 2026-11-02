<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Exceptions\DayClosedException;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\StaffSalesService;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/*
 * Security review L3: refunding yesterday's payment used to pull the order out of yesterday's sales,
 * so a closed day's figures changed after its Z report. A refund is now posted to the day it is given
 * (which must be open); the day the sale was made keeps its figures.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Abena']);
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);
    $this->cash = app(CashService::class);
    $this->days = app(DayCloseService::class);

    // Day 1: one coke sold for cash, till cashed up, day closed.
    Carbon::setTestNow('2026-11-02 18:00:00');
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('100'), '2026-11-02');
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke']); // 12.00 incl. tax
    $this->payment = $this->payments->payCash($this->order, Money::fromDecimal('12'), $this->cashier);
    $this->cash->close($session, Money::fromDecimal('112'), $this->cashier);
    $this->zReport = $this->days->close('2026-11-02', $this->cashier, $this->manager);
});

afterEach(fn () => Carbon::setTestNow());

it('posts a refund of a closed day\'s sale to the open day and leaves the closed day untouched', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('100'), '2026-11-03');

    $this->payments->refund($this->payment, 'Flat drink', $this->manager, $this->cashier);

    $payment = $this->payment->fresh();
    $order = $this->order->fresh();
    expect($payment->refund_business_date->toDateString())->toBe('2026-11-03')
        ->and($payment->refund_session_id)->toBe($session->id)
        ->and($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refund_business_date->toDateString())->toBe('2026-11-03')
        ->and($order->countsAsSale())->toBeTrue();

    // Day 1 still shows exactly what its Z report froze.
    $day1 = $this->days->report('2026-11-02');
    expect($day1['orders_paid'])->toBe(1)->and($day1['gross_sales'])->toBe(1200)
        ->and($day1['orders_refunded'])->toBe(0)->and($day1['refund_total'])->toBe(0)
        ->and($day1['by_payment_method'][0]['total'])->toBe(1200)
        ->and($day1['orders_paid'])->toBe($this->zReport->report['orders_paid'])
        ->and($day1['gross_sales'])->toBe($this->zReport->report['gross_sales']);

    // Day 2 carries the refund.
    $day2 = $this->days->report('2026-11-03');
    expect($day2['orders_paid'])->toBe(0)->and($day2['gross_sales'])->toBe(0)
        ->and($day2['orders_refunded'])->toBe(1)->and($day2['refund_total'])->toBe(1200)
        ->and($day2['cash']['cash_refunds'])->toBe(1200)
        ->and($this->cash->expected($session))->toBePesewas(8800);

    // The back-office reports agree.
    $reports = app(ReportService::class);
    expect($reports->summary('2026-11-02', '2026-11-02')['orders'])->toBe(1)
        ->and($reports->summary('2026-11-02', '2026-11-02')['refund_total'])->toBe(0)
        ->and($reports->summary('2026-11-03', '2026-11-03')['orders'])->toBe(0)
        ->and($reports->summary('2026-11-03', '2026-11-03')['orders_refunded'])->toBe(1)
        ->and($reports->summary('2026-11-03', '2026-11-03')['refund_total'])->toBe(1200)
        ->and($reports->byDay('2026-11-02', '2026-11-03')[0]['gross'])->toBe(1200)
        ->and(app(StaffSalesService::class)->between('2026-11-02', '2026-11-02')->firstWhere('user.id', $this->cashier->id)['orders'])->toBe(1);
});

it('refuses a refund while today is closed', function () {
    Carbon::setTestNow('2026-11-02 23:00:00'); // still day 1, which is closed
    expect(fn () => $this->payments->refund($this->payment, 'Flat drink', $this->manager, $this->cashier))->toThrow(DayClosedException::class);
    expect($this->payment->fresh()->isRefunded())->toBeFalse();
});

it('still drops an order refunded on its own day out of that day\'s sales', function () {
    Carbon::setTestNow('2026-11-03 12:00:00');
    $this->cash->open($this->till, $this->cashier, Money::fromDecimal('100'), '2026-11-03');
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke']);
    $payment = $this->payments->payCash($order, Money::fromDecimal('12'), $this->cashier);
    $this->payments->refund($payment, 'Wrong drink', $this->manager, $this->cashier);

    $day2 = $this->days->report('2026-11-03');
    expect($order->fresh()->countsAsSale())->toBeFalse()
        ->and($day2['orders_paid'])->toBe(0)->and($day2['gross_sales'])->toBe(0)
        ->and($day2['orders_refunded'])->toBe(1)->and($day2['refund_total'])->toBe(1200);
});
