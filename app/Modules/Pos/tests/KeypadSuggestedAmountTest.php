<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;

/*
 * Test report 9 Nov 2026, H6: the on-screen keypads ignored every key while a suggested amount with pence
 * was showing (the usual float "500.00" when opening the till; the balance when Mobile Money or card was
 * chosen), because a third decimal is refused. The first key now replaces a suggested amount.
 *
 * The keypads run in the browser (Alpine), which these tests cannot press; this guards the markup. The
 * behaviour itself was checked in a real browser on 9 Nov 2026: typing 3-0-0 opens the till with GH₵ 300.00,
 * and typing 5-0 after choosing Mobile Money asks for GH₵ 50.00 (both ignored before the fix).
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->actingAs($this->cashier);
});

it('lets the first key replace the suggested float on the open-till keypad', function () {
    Livewire::test(CashScreen::class)
        ->call('openModal', 'open')
        ->assertSet('amount', '500.00')
        ->assertSeeHtml("typed: ''")
        ->assertSeeHtml('let v = this.amount === this.typed ? this.amount : \'\';');
});

it('lets the first key replace a suggested payment amount', function () {
    $orders = app(OrderService::class);
    app(CashService::class)->open($this->till, $this->cashier, Money::of(50000), $orders->businessDate());
    $order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($order, $this->menu['coke'], null, 2, [], null, $this->cashier);

    Livewire::test(PaymentScreen::class, ['order' => $order])
        ->call('setMethod', 'momo')
        ->assertSet('amount', $order->fresh()->balanceDue()->toDecimal())
        ->assertSeeHtml("typed: { amount: '', tip: '' }")
        ->assertSeeHtml("let v = this[f] === this.typed[f] ? this[f] : '';");
});
