<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Models\DayClose;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Abena']);
    $this->manager->assignRole(Role::Manager->value);
});

it('sends a cashier to open the till before taking a payment, then back to the payment', function () {
    $this->actingAs($this->cashier);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->cashier);
    app(OrderService::class)->addItem($order, $this->menu['coke']);

    Livewire::test(PaymentScreen::class, ['order' => $order])->assertRedirect(route('pos.cash', ['then' => $order->id]));

    $this->get('/pos/cash')->assertOk()->assertSee('Till not opened')->assertSee('Open till');

    Livewire::test(CashScreen::class, ['then' => $order->id])
        ->call('openModal', 'open')
        ->assertSet('amount', '500.00')                       // the usual float, from Till settings
        ->assertSee('Which till is this device?')->assertSee('Till 1')
        ->set('tillId', $this->till->id)
        ->set('amount', '450')
        ->call('openTill')
        ->assertRedirect(route('pos.pay', $order->id));

    $session = CashierSession::query()->first();
    expect($session->till_id)->toBe($this->till->id)->and($session->opening_float)->toBePesewas(45000);

    Livewire::test(PaymentScreen::class, ['order' => $order])->call('setAmount', 1200)->call('complete')->assertRedirect();
    expect($order->fresh()->payments()->first()->cashier_session_id)->toBe($session->id);
});

it('records pay-ins and pay-outs with a reason and opens the drawer', function () {
    $this->actingAs($this->cashier);
    app(CashService::class)->open($this->till, $this->cashier, Money::fromDecimal('500'), now()->toDateString());

    Livewire::test(CashScreen::class)
        ->assertSee('Till 1')->assertSee('S0001')->assertDontSee('Expected in drawer') // cashiers count blind
        ->call('openModal', 'pay_out')->assertSee('Supplier paid in cash')
        ->set('amount', '80')->call('recordMovement')->assertSee('Please choose a reason')
        ->call('setReason', 'Supplier paid in cash')->set('reference', 'INV 12')->call('recordMovement')
        ->assertSet('modal', null)->assertSee('Pay out of GH₵ 80.00 saved')
        ->call('openModal', 'pay_in')->set('amount', '20')->call('setReason', 'Float top-up')->call('recordMovement')
        ->assertSee('Pay in of GH₵ 20.00 saved');

    $session = CashierSession::query()->first();
    expect($session->pay_outs)->toBePesewas(8000)->and($session->pay_ins)->toBePesewas(2000)
        ->and($session->movements->pluck('reference')->all())->toBe(['INV 12', null]);
});

it('cashes up from the count sheet and asks for a manager PIN when the drawer is short', function () {
    $this->actingAs($this->cashier);
    $session = app(CashService::class)->open($this->till, $this->cashier, Money::fromDecimal('500'), now()->toDateString());
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->cashier);
    app(OrderService::class)->addItem($order, $this->menu['coke']);
    app(PaymentService::class)->payCash($order, Money::fromDecimal('12'), $this->cashier); // expected 512

    $screen = Livewire::test(CashScreen::class)
        ->call('openModal', 'cash_up')->assertSee('Count the notes and coins')
        ->set('count', ['20000' => '2', '10000' => '1', '1000' => '']) // 500.00 → short by 12
        ->call('cashUp')
        ->assertSet('approval.permission', 'payments.cash_variance')->assertSee('Manager approval')
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')
        ->assertSet('approval', null)->assertSet('modal', null)
        ->assertSee('Till cashed up')->assertSee('-GH₵ 12.00');

    $session->refresh();
    expect($session->isOpen())->toBeFalse()
        ->and($session->counted_cash)->toBePesewas(50000)
        ->and($session->variance)->toBe(-1200)
        ->and($session->approved_by_id)->toBe($this->manager->id)
        ->and($session->counted_breakdown)->toBe(['20000' => 2, '10000' => 1]);

    $this->get(route('pos.cash.session', $session))->assertOk()->assertSee('Cash-up slip')->assertSee('512.00')->assertSee('Abena');
    $screen->call('reprintCashUp', $session->id)->assertSee('No receipt printer');
});

it('closes the day with a manager PIN, shows the Z report and can reopen it', function () {
    $this->actingAs($this->manager);
    $session = app(CashService::class)->open($this->till, $this->manager, Money::fromDecimal('500'), now()->toDateString());
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->manager);
    app(OrderService::class)->addItem($order, $this->menu['coke']);
    app(PaymentService::class)->payCash($order, Money::fromDecimal('12'), $this->manager);

    $screen = Livewire::test(CashScreen::class)
        ->assertSee('Expected in drawer')->assertSee('GH₵ 512.00')     // managers see the expected figure
        ->assertSee('Till 1 · Abena')                                   // blocker: open till
        ->call('openModal', 'close_day')->call('closeDay')->assertSee('Cash them up first');

    app(CashService::class)->close($session, Money::fromDecimal('512'), $this->manager);

    $screen->call('openModal', 'close_day')->set('notes', 'All good')->call('closeDay')
        ->assertSet('approval.permission', 'day.close')
        ->call('approve', '2468')
        ->assertSee('Z report Z0001 printed');

    $close = DayClose::query()->first();
    expect($close->notes)->toBe('All good')->and($close->report['gross_sales'])->toBe(1200);

    $this->get(route('pos.cash.report', ['date' => now()->toDateString()]))->assertOk()->assertSee('Z report Z0001')->assertSee('12.00');
    $this->get('/pos/cash')->assertOk()->assertSee('Day closed: Z0001');

    // New orders are refused from the home screen while the day is closed.
    Livewire::test(OrderTypeScreen::class)->call('startTakeaway')->assertSee('is closed (Z report printed)');

    $screen->call('openModal', 'reopen')->call('reopenDay')->assertSee('Please choose a reason')
        ->set('reason', 'Late customers')->call('reopenDay')->assertSet('approval.permission', 'day.reopen')
        ->call('approve', '2468')->assertSee('The day was reopened.');
    expect($close->fresh()->reopen_reason)->toBe('Late customers');
    $this->get(route('pos.cash.report'))->assertSee('X report');
});

it('keeps waiters off the cash and payment screens', function () {
    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->actingAs($waiter);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $waiter);
    app(OrderService::class)->addItem($order, $this->menu['coke']);

    $this->get('/pos/cash')->assertForbidden();
    $this->get(route('pos.pay', $order))->assertForbidden();
    Livewire::test(OrderScreen::class, ['order' => $order])->call('pay')->assertSet('error', 'Ask a cashier to take the payment.');
});
