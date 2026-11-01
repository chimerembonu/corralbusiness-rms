<?php

use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Customers\Services\SimplePointsProvider;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->customers = app(CustomerService::class);
    $this->orders = app(OrderService::class);
});

it('normalises phone numbers and finds customers by any spelling of them', function () {
    $ama = $this->customers->create(['name' => 'Ama Serwaa', 'phone' => '024 123 4567', 'address' => 'Osu, House 14']);
    expect($ama->phone_digits)->toBe('233241234567')
        ->and($this->customers->findByPhone('+233 24 123 4567')?->id)->toBe($ama->id)
        ->and($this->customers->findByPhone('0241234567')?->id)->toBe($ama->id)
        ->and($this->customers->findByPhone('00233241234567')?->id)->toBe($ama->id)
        ->and($this->customers->findByPhone('0241234568'))->toBeNull()
        ->and($ama->defaultAddress()?->address)->toBe('Osu, House 14')
        ->and($this->customers->search('ser')->pluck('name')->all())->toBe(['Ama Serwaa'])
        ->and($this->customers->search('1234')->pluck('name')->all())->toBe(['Ama Serwaa'])
        ->and($this->customers->search(''))->toHaveCount(0);

    expect(fn () => $this->customers->create(['name' => 'Someone', 'phone' => '+233241234567']))->toThrow(CustomerException::class, 'already exists');
    expect(fn () => $this->customers->create(['name' => '', 'phone' => '020']))->toThrow(CustomerException::class);
});

it('attaches a customer to an order, keeps their history and stats, and awards points when switched on', function () {
    app(SettingsService::class)->set(SimplePointsProvider::SETTING, 2);
    $ama = $this->customers->create(['name' => 'Ama', 'phone' => '0241234567']);

    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke'], quantity: 3); // 36.00
    $this->customers->attach($order, $ama, $this->cashier);
    expect($order->fresh()->customer_id)->toBe($ama->id)->and($order->fresh()->customer_name)->toBe('Ama')->and($order->fresh()->customer_phone)->toBe('0241234567');

    app(PaymentService::class)->payCash($order, Money::fromDecimal('36'), $this->cashier);

    $ama->refresh();
    expect($ama->orders_count)->toBe(1)->and($ama->total_spent)->toBePesewas(3600)->and($ama->last_order_at)->not->toBeNull()
        ->and($ama->points_balance)->toBe(72)
        ->and($this->customers->history($ama)->pluck('id')->all())->toBe([$order->id]);

    $this->customers->detach($order->fresh());
    expect($order->fresh()->customer_id)->toBeNull();
});

it('remembers delivery customers and their addresses from the phone number', function () {
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    app(DeliveryService::class)->setDetails($order, 'Kwabena', '020 765 4321', 'Labone, near the church', 'Blue gate', Money::fromDecimal('10'), $this->cashier);

    $customer = $this->customers->findByPhone('0207654321');
    expect($customer)->not->toBeNull()->and($customer->name)->toBe('Kwabena')
        ->and($order->fresh()->customer_id)->toBe($customer->id)
        ->and($customer->addresses()->count())->toBe(1)->and($customer->defaultAddress()->notes)->toBe('Blue gate');

    // Same customer, new address → remembered as a second address; the first stays the usual one.
    $second = $this->orders->open(OrderType::Delivery, $this->cashier);
    app(DeliveryService::class)->setDetails($second, '', '+233207654321', 'Office, Ridge Tower', null, null, $this->cashier);
    expect($customer->addresses()->count())->toBe(2)->and($customer->fresh()->defaultAddress()->address)->toBe('Labone, near the church');

    // A new delivery for a known customer gets the usual address when attached.
    $third = $this->orders->open(OrderType::Delivery, $this->cashier);
    $this->customers->attach($third, $customer);
    expect($third->fresh()->delivery_address)->toBe('Labone, near the church')->and($third->fresh()->customer_name)->toBe('Kwabena');

    // No phone → nothing remembered.
    expect(Customer::count())->toBe(1);
});
