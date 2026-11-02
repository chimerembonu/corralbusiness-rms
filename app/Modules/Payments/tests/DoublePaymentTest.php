<?php

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Event;

/*
 * Security review M2: a double tap on Pay, or two tills holding the same order, used to record two
 * payments and fire the paid / print / outbox events twice; sending to the kitchen twice deducted
 * stock twice. The order is now locked and re-read inside the transaction, and stock movements are
 * keyed to the order line so a line can only ever be deducted (or put back) once.
 */
beforeEach(function () {
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke'], quantity: 2); // 24.00 with tax
});

it('refuses a second full payment taken from a stale copy of the order', function () {
    Event::fake([OrderPaid::class]);
    $staleCopy = Order::query()->findOrFail($this->order->id); // what a second till (or the first tap) holds

    $this->payments->payCash($this->order, Money::fromDecimal('24'), $this->cashier);
    expect($staleCopy->balanceDue())->toBePesewas(2400); // the stale copy still thinks nothing was paid

    expect(fn () => $this->payments->payCash($staleCopy, Money::fromDecimal('24'), $this->cashier))
        ->toThrow(PaymentException::class);
    expect(fn () => $this->payments->payCard($staleCopy, Money::fromDecimal('24'), null, $this->cashier))
        ->toThrow(PaymentException::class);

    expect(Payment::query()->where('order_id', $this->order->id)->count())->toBe(1)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->fresh()->paid_total)->toBePesewas(2400);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('refuses a partial payment that would overshoot once the real balance is read', function () {
    $staleCopy = Order::query()->findOrFail($this->order->id);
    $this->payments->payCard($this->order, Money::fromDecimal('20'), null, $this->cashier);

    expect(fn () => $this->payments->payCard($staleCopy, Money::fromDecimal('10'), null, $this->cashier))
        ->toThrow(PaymentException::class);
    expect(Payment::query()->where('order_id', $this->order->id)->count())->toBe(1);
});

it('sends each line to the kitchen only once, even from a stale copy', function () {
    Event::fake([OrderSentToKitchen::class]);
    $staleCopy = Order::query()->findOrFail($this->order->id);

    $this->orders->sendToKitchen($this->order, $this->cashier);
    $this->orders->sendToKitchen($staleCopy, $this->cashier);

    Event::assertDispatchedTimes(OrderSentToKitchen::class, 1);
});

it('deducts stock for a line once however many times the kitchen event fires, and restocks it once', function () {
    $coke = $this->menu['coke'];
    $coke->forceFill(['track_stock' => true, 'stock_qty' => 10])->save();
    $stock = app(StockService::class);
    $lines = $this->order->items()->get();

    $this->orders->sendToKitchen($this->order, $this->cashier); // the real listener deducts 2
    $stock->deductForSale($this->order, $lines, $this->cashier); // a duplicate event
    $stock->deductForSale($this->order->fresh(), $this->order->items()->get(), $this->cashier);

    expect((float) $coke->fresh()->stock_qty)->toBe(8.0)
        ->and(StockMovement::query()->where('order_item_id', $lines->first()->id)->where('type', StockMovementType::Sale->value)->count())->toBe(1);

    $stock->restockLine($this->order, $lines->first(), $this->cashier);
    $stock->restockLine($this->order, $lines->first()->fresh(), $this->cashier);
    expect((float) $coke->fresh()->stock_qty)->toBe(10.0)
        ->and(StockMovement::query()->where('order_item_id', $lines->first()->id)->pluck('type')->map->value->all())->toBe(['sale', 'void']);
});
