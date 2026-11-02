<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;

/*
 * Security review M5: splitting a training order produced a real order (the training flag was not
 * copied), so practice bills could land in the real takings. The split-off order stays a practice one.
 */
beforeEach(function () {
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->orders = app(OrderService::class);
    $this->menu['coke']->forceFill(['track_stock' => true, 'stock_qty' => 10])->save();
});

it('keeps a split-off order in training mode, out of stock and out of the reports', function () {
    app(TrainingMode::class)->set(true);
    $practice = $this->orders->open(OrderType::DineIn, $this->cashier);
    $coke = $this->orders->addItem($practice, $this->menu['coke'], quantity: 2);
    $this->orders->addItem($practice, $this->menu['water']);
    app(TrainingMode::class)->set(false); // the device leaves training mode before the bill is split

    $split = $this->orders->split($practice, [$coke->id], $this->cashier);
    expect($split->is_training)->toBeTrue()->and($split->fresh()->is_training)->toBeTrue();

    $this->orders->sendToKitchen($split, $this->cashier);
    $payment = app(PaymentService::class)->payCash($split->fresh(), Money::fromDecimal('30'), $this->cashier);

    expect($payment->is_training)->toBeTrue()
        ->and((float) $this->menu['coke']->fresh()->stock_qty)->toBe(10.0)
        ->and(app(ReportService::class)->summary(now()->toDateString(), now()->toDateString())['orders'])->toBe(0);
});

it('splits a real order into a real order', function () {
    $order = $this->orders->open(OrderType::DineIn, $this->cashier);
    $coke = $this->orders->addItem($order, $this->menu['coke']);
    $this->orders->addItem($order, $this->menu['water']);

    expect($this->orders->split($order, [$coke->id], $this->cashier)->is_training)->toBeFalse();
});
