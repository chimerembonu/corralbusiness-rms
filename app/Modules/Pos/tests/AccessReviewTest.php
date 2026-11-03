<?php

use App\Modules\Customers\Livewire\CustomersScreen;
use App\Modules\Customers\Models\Customer;
use App\Modules\Kitchen\Livewire\KitchenDisplay;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrdersList;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Re-review of access control: the till's "new customer" form passed every field through (a browser
 * could add account credit), riders and kitchen staff could open order screens, training mode was a
 * replayable cookie, and ids sent to till actions were looked up across the whole restaurant.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->rider = User::factory()->forBranch($this->branch)->withPin('4001')->create(['name' => 'Kwesi']);
    $this->rider->assignRole(Role::Rider->value);
    $this->cook = User::factory()->forBranch($this->branch)->withPin('3001')->create(['name' => 'Esi']);
    $this->cook->assignRole(Role::Kitchen->value);
    $this->orders = app(OrderService::class);
});

/** What the till cookie does on a real request: this device is that till. */
function onThisTill(Till $till): void
{
    app()->forgetInstance(TrainingMode::class);
    app(CurrentTill::class)->remember($till);
}

it('ignores account settings sent with the till\'s new-customer form', function () {
    $this->actingAs($this->cashier);
    Livewire::test(CustomersScreen::class)
        ->set('newCustomer', ['name' => 'Ghost Ltd', 'phone' => '0200000001', 'address' => 'Nowhere', 'allow_account' => true, 'credit_limit' => null])
        ->call('create');
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    Livewire::test(OrderScreen::class, ['order' => $order])
        ->set('newCustomer', ['name' => 'Ghost Two', 'phone' => '0200000002', 'allow_account' => true])
        ->call('createCustomer');

    expect(Customer::query()->where('name', 'Ghost Ltd')->sole()->allow_account)->toBeFalse()
        ->and(Customer::query()->where('name', 'Ghost Two')->sole()->allow_account)->toBeFalse();
});

it('sends a rider to the rider screen and keeps them off the order screens', function () {
    $this->actingAs($this->rider);
    Livewire::test(OrderTypeScreen::class)->assertRedirect(route('pos.rider'));
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    Livewire::test(OrderScreen::class, ['order' => $order])->assertForbidden();
    $this->get('/pos/orders')->assertForbidden(); // the order list shows every customer's name, phone and address (re-review N2)
});

it('keeps kitchen staff off the order list but lets cashiers and waiters in', function () {
    $this->actingAs($this->cook);
    $this->get('/pos/orders')->assertForbidden();
    $this->actingAs($this->cashier);
    $this->get('/pos/orders')->assertOk();
});

it('sends kitchen staff to the kitchen display, at sign-in and from the till home screen', function () {
    $this->actingAs($this->cook);
    Livewire::test(OrderTypeScreen::class)->assertRedirect(route('kitchen.display'));
});

it('lets a stock counter use the home screen but not start or resume an order', function () {
    $counter = User::factory()->forBranch($this->branch)->withPin('5001')->create(['name' => 'Yaa']);
    $counter->assignRole(Role::Inventory->value);
    $this->actingAs($counter);
    $this->get('/pos')->assertOk();
    Livewire::test(OrderTypeScreen::class)->assertOk()
        ->assertDontSee('Takeaway')->assertSee('Your role does not take orders'); // the order tiles are not offered at all
    $parked = $this->orders->open(OrderType::Takeaway, $this->cashier);
    // Even a forced press is refused, with the till's own message line rather than an error box.
    Livewire::test(OrderTypeScreen::class)->call('startTakeaway')->assertSet('error', __('errors.action.forbidden'))->assertNoRedirect();
    Livewire::test(OrderTypeScreen::class)->call('resume', $parked->id)->assertSet('error', __('errors.action.forbidden'))->assertNoRedirect();
    expect(Order::query()->count())->toBe(1);
});

it('lands kitchen staff on the kitchen display after a PIN sign-in', function () {
    Livewire::test(PinLogin::class)->call('login', '3001')->assertRedirect(route('kitchen.display'));
});

it('keeps training mode on the till row, not in a cookie, and clears it at day close', function () {
    $this->actingAs($this->manager);
    onThisTill($this->till);

    Livewire::test(OrderTypeScreen::class)->call('toggleTraining')->call('approve', '2468')->assertRedirect(route('pos.home'));
    expect($this->till->fresh()->training_mode)->toBeTrue();

    // A fresh request on the same till reads the state from the till row.
    onThisTill($this->till->fresh());
    expect(app(TrainingMode::class)->isOn())->toBeTrue();
    Livewire::test(OrderTypeScreen::class)->assertSee('End training mode');

    // The old cookie means nothing any more: a till that is not in training stays out of it whatever the browser sends.
    $this->till->forceFill(['training_mode' => false])->save();
    request()->cookies->set('rms_training', '1');
    onThisTill($this->till->fresh());
    expect(app(TrainingMode::class)->isOn())->toBeFalse();
    $this->till->forceFill(['training_mode' => true])->save();

    $session = app(CashService::class)->open($this->till, $this->cashier, Money::fromDecimal('100'), now()->toDateString());
    app(CashService::class)->close($session, Money::fromDecimal('100'), $this->cashier);
    app(DayCloseService::class)->close(now()->toDateString(), $this->cashier, $this->manager);
    expect($this->till->fresh()->training_mode)->toBeFalse();
});

it('refuses to switch training mode on a device that has not chosen a till', function () {
    $this->actingAs($this->manager);
    Livewire::test(OrderTypeScreen::class)->call('toggleTraining')->call('approve', '2468')
        ->assertSet('error', 'Choose a till for this device first (sign out, then pick the till).');
    expect(Till::query()->where('training_mode', true)->exists())->toBeFalse();
});

it('looks up ids sent to till actions in this branch only, and locks the kitchen branch', function () {
    $other = Branch::query()->create(['tenant_id' => $this->branch->tenant_id, 'name' => 'Airport', 'code' => 'AP']);
    $elsewhere = Tenancy::runAs($this->branch->tenant, $other, fn () => $this->orders->open(OrderType::Takeaway, $this->cashier));

    $this->actingAs($this->cashier);
    Livewire::test(OrdersList::class)->call('open', $elsewhere->id)->assertDontSee('#'.$elsewhere->displayNumber());
    // Another branch's order is "not here", in plain words, and nothing about it is shown or done.
    Livewire::test(OrdersList::class)->call('reprint', $elsewhere->id)->assertSet('error', __('errors.action.gone'))->assertDontSee('#'.$elsewhere->displayNumber());
    Livewire::test(OrderTypeScreen::class)->call('resume', $elsewhere->id)->assertSet('error', __('errors.action.gone'))->assertNoRedirect();
    expect(fn () => Livewire::test(KitchenDisplay::class)->set('branchId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);
});
