<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\CashierSessionStatus;
use App\Modules\Payments\Enums\DayCloseStatus;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Events\CashierSessionClosed;
use App\Modules\Payments\Events\DayClosed;
use App\Modules\Payments\Exceptions\CashException;
use App\Modules\Payments\Exceptions\DayClosedException;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Abena']);
    $this->manager->assignRole(Role::Manager->value);
    $this->waiter = User::factory()->forBranch($this->branch)->create(['name' => 'Ama']);
    $this->waiter->assignRole(Role::Waiter->value);
    $this->cash = app(CashService::class);
    $this->days = app(DayCloseService::class);
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);
    $this->today = now()->toDateString();
});

function cokeOrder(int $quantity = 1)
{
    $order = test()->orders->open(OrderType::Takeaway, test()->cashier);
    test()->orders->addItem($order, test()->menu['coke'], quantity: $quantity); // 12.00 each incl. tax

    return $order;
}

it('opens a till with a float, links cash payments to the session and works out the expected cash', function () {
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('500'), $this->today);

    expect($session->status)->toBe(CashierSessionStatus::Open)
        ->and($session->number)->toBe(1)
        ->and($session->displayNumber())->toBe('S0001')
        ->and($this->cash->current($this->cashier)?->id)->toBe($session->id);

    $payment = $this->payments->payCash(cokeOrder(2), Money::fromDecimal('30'), $this->cashier, Money::fromDecimal('2')); // 24 + 2 tip
    $this->payments->payMobileMoney(cokeOrder(), MomoProvider::Mtn, Money::fromDecimal('12'), 'MP1', $this->cashier);

    expect($payment->cashier_session_id)->toBe($session->id)
        ->and($this->cash->expected($session))->toBePesewas(50000 + 2400 + 200);

    $this->cash->payIn($session, Money::fromDecimal('100'), 'Change from the bank', $this->cashier);
    $this->cash->payOut($session, Money::fromDecimal('40'), 'Supplier paid in cash', $this->cashier, 'INV-9');

    $session->refresh();
    expect($this->cash->expected($session))->toBePesewas(50000 + 2400 + 200 + 10000 - 4000)
        ->and($session->pay_ins)->toBePesewas(10000)
        ->and($session->pay_outs)->toBePesewas(4000)
        ->and($session->movements)->toHaveCount(2)
        ->and(OutboxEvent::query()->where('event_type', 'cash.movement_recorded')->count())->toBe(2)
        ->and(Activity::query()->where('log_name', 'cash_movement')->count())->toBe(2);
});

it('refuses a second session on the same till, a pay-out bigger than the drawer, and tills opened by waiters', function () {
    $this->cash->open($this->till, $this->cashier, Money::fromDecimal('500'), $this->today);
    expect(fn () => $this->cash->open($this->till, $this->manager, Money::fromDecimal('100'), $this->today))->toThrow(CashException::class, 'Till 1 is already open');

    $session = $this->cash->current($this->cashier);
    expect(fn () => $this->cash->payOut($session, Money::fromDecimal('600'), 'Bank deposit', $this->cashier))->toThrow(CashException::class, 'only GH₵ 500.00');
    expect(fn () => $this->cash->payIn($session, Money::fromDecimal('10'), '', $this->cashier))->toThrow(CashException::class);
    expect(fn () => $this->cash->payIn($session, Money::fromDecimal('10'), 'Top-up', $this->waiter))->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->cash->open(null, $this->waiter, Money::fromDecimal('100'), $this->today))->toThrow(ManagerPinRequiredException::class);
});

it('cashes up within the tolerance without a manager, and needs one beyond it', function () {
    Event::fake([CashierSessionClosed::class]);
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('500'), $this->today);
    $this->payments->payCash(cokeOrder(), Money::fromDecimal('12'), $this->cashier); // expected 512.00

    // Short by GHS 20 → manager needed.
    expect(fn () => $this->cash->close($session, Money::fromDecimal('492'), $this->cashier))->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->cash->close($session, Money::fromDecimal('492'), $this->cashier, approvedBy: $this->cashier))->toThrow(ManagerPinRequiredException::class);

    // Short by GHS 2 (tolerance is 5) → fine, counted from the note sheet.
    $closed = $this->cash->close($session, CashService::countFromBreakdown([20000 => 2, 10000 => 1, 500 => 2]), $this->cashier, [20000 => 2, 10000 => 1, 500 => 2], 'Coins short');

    expect($closed->status)->toBe(CashierSessionStatus::Closed)
        ->and($closed->expected_cash)->toBePesewas(51200)
        ->and($closed->counted_cash)->toBePesewas(51000)
        ->and($closed->variance)->toBe(-200)
        ->and($closed->approved_by_id)->toBeNull()
        ->and($closed->counted_breakdown)->toBe(['20000' => 2, '10000' => 1, '500' => 2])
        ->and($closed->totals[0]['method'])->toBe('cash')
        ->and($closed->totals[0]['total'])->toBe(1200)
        ->and($this->cash->current($this->cashier))->toBeNull();
    Event::assertDispatched(CashierSessionClosed::class);

    // A big variance accepted by a manager is recorded against them.
    $again = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('100'), $this->today);
    $closed = $this->cash->close($again, Money::fromDecimal('50'), $this->cashier, approvedBy: $this->manager);
    expect($closed->variance)->toBe(-5000)->and($closed->approved_by_id)->toBe($this->manager->id);
    expect(fn () => $this->cash->payIn($closed, Money::fromDecimal('1'), 'x', $this->cashier))->toThrow(CashException::class, 'already cashed up');
});

it('counts cash refunds against the session they were refunded in', function () {
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('100'), $this->today);
    $payment = $this->payments->payCash(cokeOrder(), Money::fromDecimal('12'), $this->cashier);
    $this->payments->refund($payment, 'Flat drink', $this->manager, $this->cashier);

    expect($payment->fresh()->refund_session_id)->toBe($session->id)
        ->and($this->cash->expected($session))->toBePesewas(10000)
        ->and($session->fresh()->cash_refunds)->toBePesewas(1200);
});

it('produces an X report that adds up the tills and sales', function () {
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('500'), $this->today);
    $this->payments->payCash(cokeOrder(3), Money::fromDecimal('36'), $this->cashier);
    $this->payments->payCard(cokeOrder(), Money::fromDecimal('12'), '1234', $this->cashier);
    $this->orders->open(OrderType::DineIn, $this->cashier); // still open

    $report = $this->days->report($this->today);

    expect($report['gross_sales'])->toBe(4800)
        ->and($report['orders_paid'])->toBe(2)
        ->and($report['orders_open'])->toBe(1)
        ->and($report['cash']['opening_float'])->toBe(50000)
        ->and($report['cash']['cash_sales'])->toBe(3600)
        ->and($report['cash']['expected'])->toBe(53600)
        ->and($report['cash']['counted'])->toBe(0)
        ->and($report['sessions'][0]['number'])->toBe('S0001')
        ->and($report['sessions'][0]['counted'])->toBeNull()
        ->and(collect($report['by_payment_method'])->pluck('total', 'method')->all())->toBe(['cash' => 3600, 'card' => 1200])
        ->and(json_encode($report))->not->toBeFalse();
});

it('will not close the day while orders or tills are open, then closes it with a Z report and locks sales', function () {
    Event::fake([DayClosed::class]);
    $session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('500'), $this->today);
    $open = cokeOrder();
    $this->payments->payCash(cokeOrder(), Money::fromDecimal('12'), $this->cashier);

    expect($this->days->blockers($this->today))->toHaveCount(2);
    expect(fn () => $this->days->close($this->today, $this->manager))->toThrow(CashException::class, 'still open');
    expect(fn () => $this->days->close($this->today, $this->cashier))->toThrow(ManagerPinRequiredException::class);

    $this->orders->void($open, 'Customer left', $this->manager);
    $this->cash->close($session, Money::fromDecimal('512'), $this->cashier);
    expect($this->days->blockers($this->today))->toBe([]);

    $close = $this->days->close($this->today, $this->cashier, $this->manager, 'Quiet day');

    expect($close->displayNumber())->toBe('Z0001')
        ->and($close->status)->toBe(DayCloseStatus::Closed)
        ->and($close->approved_by_id)->toBe($this->manager->id)
        ->and($close->report['gross_sales'])->toBe(1200)
        ->and($close->report['cash']['counted'])->toBe(51200)
        ->and($close->report['cash']['variance'])->toBe(0)
        ->and($this->days->isClosed($this->today))->toBeTrue();
    Event::assertDispatched(DayClosed::class);

    // Nothing more can be sold or paid on a closed day.
    expect(fn () => $this->orders->open(OrderType::Takeaway, $this->cashier))->toThrow(DayClosedException::class);
    expect(fn () => $this->days->close($this->today, $this->manager))->toThrow(DayClosedException::class);

    // A manager reopens it; sales resume; the next close gets the next Z number.
    expect(fn () => $this->days->reopen($close, $this->cashier, 'Late table'))->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->days->reopen($close, $this->manager, ''))->toThrow(CashException::class);
    $this->days->reopen($close, $this->manager, 'Late table');
    expect($close->fresh()->status)->toBe(DayCloseStatus::Reopened)
        ->and($this->days->isClosed($this->today))->toBeFalse();

    $late = cokeOrder();
    expect($late->exists)->toBeTrue();
    $this->orders->void($late, 'Test', $this->manager);
    expect($this->days->close($this->today, $this->manager)->displayNumber())->toBe('Z0002');
});
