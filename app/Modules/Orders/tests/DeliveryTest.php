<?php

use App\Modules\Orders\Enums\DeliveryStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\DeliveryStatusChanged;
use App\Modules\Orders\Events\RiderAssigned;
use App\Modules\Orders\Exceptions\DeliveryException;
use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->rider = User::factory()->forBranch($this->branch)->create(['name' => 'Kwesi']);
    $this->rider->assignRole(Role::Rider->value);
    app(SettingsService::class)->set(TillSettings::DELIVERY_FEE, 1000);
    $this->orders = app(OrderService::class);
    $this->delivery = app(DeliveryService::class);
});

it('opens a delivery order with the default fee and adds it after tax', function () {
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    expect($order->delivery_status)->toBe(DeliveryStatus::Pending)->and($order->delivery_fee)->toBePesewas(1000)->and($order->total)->toBePesewas(1000);

    $this->delivery->setDetails($order, 'Ama', '024 123 4567', 'House 12, Osu Oxford St', 'Call at the gate', null, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke'], quantity: 2);   // 20.00 + 4.00 tax

    $order = $order->fresh();
    expect($order->customer_phone)->toBe('024 123 4567')
        ->and($order->delivery_address)->toBe('House 12, Osu Oxford St')
        ->and($order->total)->toBePesewas(3400)
        ->and($order->tax_total)->toBePesewas(400);                      // fee is not taxed

    $this->delivery->setDetails($order, 'Ama', '024 123 4567', 'House 12', null, Money::fromDecimal('15'), $this->cashier);
    expect($order->fresh()->total)->toBePesewas(3900)
        ->and(OutboxEvent::query()->where('event_type', 'orders.delivery_details_changed')->count())->toBe(2);

    expect(fn () => $this->delivery->setDetails($order, 'Ama', '', 'Somewhere'))->toThrow(DeliveryException::class, 'phone')
        ->and(fn () => $this->delivery->setDetails($order, 'Ama', '024', ''))->toThrow(DeliveryException::class, 'address');

    $takeaway = $this->orders->open(OrderType::Takeaway, $this->cashier);
    expect($takeaway->delivery_fee)->toBePesewas(0)
        ->and(fn () => $this->delivery->setDetails($takeaway, 'X', '1', 'Y'))->toThrow(DeliveryException::class, 'not a delivery');
});

it('assigns a rider and walks the delivery from out to delivered', function () {
    Event::fake([RiderAssigned::class, DeliveryStatusChanged::class]);
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    $this->delivery->setDetails($order, 'Ama', '024', 'Osu');
    $this->orders->addItem($order, $this->menu['coke']);
    $this->orders->sendToKitchen($order, $this->cashier);

    expect(fn () => $this->delivery->markOut($order, $this->cashier))->toThrow(DeliveryException::class, 'Assign a rider')
        ->and(fn () => $this->delivery->assignRider($order, $this->cashier, $this->cashier))->toThrow(DeliveryException::class, 'not a rider');

    $this->delivery->assignRider($order, $this->rider, $this->cashier);
    expect($order->fresh()->delivery_status)->toBe(DeliveryStatus::Assigned)->and($order->fresh()->rider_id)->toBe($this->rider->id);
    Event::assertDispatched(RiderAssigned::class);

    expect($this->delivery->forRider($this->rider)->pluck('id')->all())->toBe([$order->id])
        ->and($this->delivery->board()->first()->id)->toBe($order->id)
        ->and($this->delivery->riders()->pluck('id')->all())->toBe([$this->rider->id]);

    $this->delivery->markOut($order, $this->rider);
    expect($order->fresh()->delivery_status)->toBe(DeliveryStatus::Out)->and($order->fresh()->out_at)->not->toBeNull();

    // Cash on delivery: the rider's cash is recorded when they are back; the order stays a normal sale.
    app(PaymentService::class)->payCash($order->fresh(), Money::fromDecimal('30'), $this->cashier);

    $this->delivery->markDelivered($order->fresh(), $this->rider);
    $order = $order->fresh();
    expect($order->delivery_status)->toBe(DeliveryStatus::Delivered)->and($order->delivered_at)->not->toBeNull()->and($order->isPaid())->toBeTrue();
    Event::assertDispatchedTimes(DeliveryStatusChanged::class, 2);

    expect(fn () => $this->delivery->markOut($order, $this->rider))->toThrow(DeliveryException::class, 'already Delivered')
        ->and($this->delivery->forRider($this->rider))->toHaveCount(0);

    $summary = app(DailySummaryService::class)->forDate(now()->toDateString());
    expect($summary['delivery_fee_total'])->toBePesewas(1000);
});

it('marks a failed delivery and can reopen it for another rider', function () {
    $order = $this->orders->open(OrderType::Delivery, $this->cashier);
    $this->delivery->setDetails($order, 'Ama', '024', 'Osu');
    $this->delivery->assignRider($order, $this->rider);
    $this->delivery->markOut($order, $this->rider);
    $this->delivery->markFailed($order, 'Customer not answering', $this->rider);

    expect($order->fresh()->delivery_status)->toBe(DeliveryStatus::Failed)->and($order->fresh()->delivery_failure)->toBe('Customer not answering');

    $this->delivery->reopen($order->fresh(), $this->cashier);
    expect($order->fresh()->delivery_status)->toBe(DeliveryStatus::Pending)->and($order->fresh()->rider_id)->toBeNull();
});
