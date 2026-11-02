<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;
use App\Support\Money\Money;
use Livewire\Livewire;

/*
 * Security review M4: the delivery fee was free text from any till user and flowed straight into the
 * total. It now defaults from Till settings and any other figure is a price edit: manager PIN unless
 * the user may edit prices.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Abena']);
    $this->manager->assignRole(Role::Manager->value);
    app(SettingsService::class)->set(TillSettings::DELIVERY_FEE, 1000);
    $this->orders = app(OrderService::class);
    $this->delivery = app(DeliveryService::class);
});

it('lets a cashier keep the default fee but not change it without a manager', function () {
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    expect($order->delivery_fee)->toBePesewas(1000);

    $this->delivery->setDetails($order, 'Ama', '024', 'Osu', null, Money::fromDecimal('10'), $this->cashier);
    $this->delivery->setDetails($order, 'Ama', '024', 'Osu, blue gate', null, null, $this->cashier); // no fee given: unchanged
    expect($order->fresh()->delivery_fee)->toBePesewas(1000)->and($order->fresh()->delivery_address)->toBe('Osu, blue gate');

    expect(fn () => $this->delivery->setDetails($order, 'Ama', '024', 'Osu', null, Money::zero(), $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->delivery->setDetails($order, 'Ama', '024', 'Osu', null, Money::fromDecimal('50'), $this->cashier, $this->cashier))
        ->toThrow(ManagerPinRequiredException::class); // a cashier cannot approve for themselves
    expect($order->fresh()->delivery_fee)->toBePesewas(1000);

    $this->delivery->setDetails($order, 'Ama', '024', 'Osu', null, Money::zero(), $this->cashier, $this->manager);
    expect($order->fresh()->delivery_fee)->toBePesewas(0)
        ->and(Activity::query()->where('description', 'delivery_fee_changed')->sole()->properties['approved_by'])->toBe($this->manager->id);

    // Someone with the price-edit permission needs no second approver.
    $this->delivery->setDetails($order, 'Ama', '024', 'Osu', null, Money::fromDecimal('15'), $this->manager);
    expect($order->fresh()->delivery_fee)->toBePesewas(1500);
});

it('opens the manager keypad when a cashier changes the fee on a new delivery order', function () {
    $this->actingAs($this->cashier);

    $screen = Livewire::test(OrderTypeScreen::class)
        ->call('startDelivery')->assertSet('delivery.fee', '10.00')
        ->set('delivery.name', 'Ama')->set('delivery.phone', '024')->set('delivery.address', 'Osu')->set('delivery.fee', '')
        ->call('openDelivery')
        ->assertSet('approval.permission', 'orders.price_edit')->assertSee('Set the delivery fee to GH₵ 0.00');
    expect(Order::query()->count())->toBe(0); // nothing opened until it is approved

    $screen->call('approve', '2468')->assertRedirect();
    $order = Order::query()->sole();
    expect($order->delivery_fee)->toBePesewas(0)->and($order->customer_name)->toBe('Ama');

    // The default fee needs no PIN.
    Livewire::test(OrderTypeScreen::class)
        ->call('startDelivery')->set('delivery.name', 'Yaw')->set('delivery.phone', '020')->set('delivery.address', 'Labone')
        ->call('openDelivery')->assertSet('approval', null)->assertRedirect();
    expect(Order::query()->count())->toBe(2);
});

it('opens the manager keypad when a cashier changes the fee on an existing order', function () {
    $this->actingAs($this->cashier);
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    $this->delivery->setDetails($order, 'Ama', '024', 'Osu', null, null, $this->cashier);

    Livewire::test(OrderScreen::class, ['order' => $order])
        ->call('editDelivery')->set('delivery.address', 'Osu, blue gate')->call('saveDelivery')->assertSet('modal', null) // address only: fine
        ->call('editDelivery')->set('delivery.fee', '0')->call('saveDelivery')
        ->assertSet('approval.permission', 'orders.price_edit')
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')->assertSet('approval', null)->assertSet('modal', null);

    expect($order->fresh()->delivery_fee)->toBePesewas(0)->and($order->fresh()->delivery_address)->toBe('Osu, blue gate');
});
