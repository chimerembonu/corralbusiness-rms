<?php

use App\Modules\Customers\Livewire\CustomersScreen;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
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
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1001')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    app(CashService::class)->open(Till::create(['name' => 'T1', 'code' => 'T1']), $this->cashier, Money::fromDecimal('100'), now()->toDateString());
    $this->hotel = app(CustomerService::class)->create(['name' => 'Golden Tulip', 'phone' => '0302213344', 'address' => 'Liberation Road', 'allow_account' => true, 'credit_limit' => Money::fromDecimal('500')]);
    $this->actingAs($this->cashier);
});

it('finds or creates a customer from the order screen and pays on account', function () {
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->cashier);
    app(OrderService::class)->addItem($order, $this->menu['coke'], quantity: 2); // 24.00

    Livewire::test(OrderScreen::class, ['order' => $order])
        ->call('openModal', 'customer')
        ->set('customerSearch', '0302')->assertSee('Golden Tulip')
        ->set('newCustomer', ['name' => 'Ama', 'phone' => '0241234567'])->call('createCustomer')
        ->assertSet('modal', null)->assertSee('Ama is on this order');
    expect($order->fresh()->customer->name)->toBe('Ama');

    Livewire::test(PaymentScreen::class, ['order' => $order])->assertDontSee('On account'); // Ama may not

    Livewire::test(OrderScreen::class, ['order' => $order])
        ->call('openModal', 'customer')->assertSee('Ama')->assertSee('Remove customer')
        ->call('attachCustomer', $this->hotel->id)->assertSee('Golden Tulip is on this order');

    Livewire::test(PaymentScreen::class, ['order' => $order])
        ->assertSee('On account')
        ->call('setMethod', 'account')->assertSet('amount', '24.00')->assertSee('Golden Tulip')
        ->call('complete')->assertRedirect(route('pos.receipt', [$order, 'print' => 1]));

    expect($order->fresh()->isPaid())->toBeTrue()->and($this->hotel->fresh()->account_balance)->toBe(2400);
    $this->get(route('pos.receipt', $order))->assertOk()->assertSee('On account')->assertSee('Golden Tulip');
});

it('fills a delivery from the phone number and remembers new delivery customers', function () {
    Livewire::test(OrderTypeScreen::class)
        ->call('startDelivery')
        ->set('delivery.phone', '030 221 3344')->call('lookupDeliveryCustomer')
        ->assertSet('delivery.name', 'Golden Tulip')->assertSet('delivery.address', 'Liberation Road')->assertSee('Golden Tulip found')
        ->set('delivery.phone', '0555555555')->call('lookupDeliveryCustomer')->assertSee('will be saved with this order')
        ->set('delivery.name', 'Nana Yaa')->set('delivery.address', 'East Legon')->call('openDelivery')->assertRedirect();

    $order = Order::query()->latest('opened_at')->first();
    $nana = app(CustomerService::class)->findByPhone('0555555555');
    expect($nana?->name)->toBe('Nana Yaa')->and($order->customer_id)->toBe($nana->id)->and($nana->defaultAddress()->address)->toBe('East Legon');
});

it('takes an account payment and adds customers on the Customers screen', function () {
    $this->hotel->forceFill(['account_balance' => 15000])->save();
    $this->get('/pos')->assertSee('Customers');
    $this->get('/pos/customers?search=tulip')->assertOk()->assertSee('Golden Tulip')->assertSee('GH₵ 150.00');

    Livewire::test(CustomersScreen::class, ['search' => 'tulip'])
        ->call('select', $this->hotel->id)->assertSee('Receive payment')->assertSee('Liberation Road')
        ->call('openModal', 'receive')->assertSet('amount', '150.00')
        ->set('amount', '100')->call('receive')
        ->assertSee('GH₵ 100.00 received from Golden Tulip. Now owes GH₵ 50.00.')
        ->call('openModal', 'new')->set('newCustomer', ['name' => 'Joe', 'phone' => '0270007777', 'address' => ''])->call('create')
        ->assertSee('Joe saved.');
    expect($this->hotel->fresh()->account_balance)->toBe(5000)->and(Customer::query()->where('name', 'Joe')->exists())->toBeTrue();

    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->actingAs($waiter)->get('/pos/customers')->assertForbidden();
});
