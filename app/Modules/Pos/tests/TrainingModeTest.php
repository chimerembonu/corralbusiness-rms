<?php

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1001')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->session = app(CashService::class)->open(Till::create(['name' => 'T1', 'code' => 'T1']), $this->cashier, Money::fromDecimal('100'), now()->toDateString());
});

it('marks orders taken in training mode and keeps them out of reports, cash and stock', function () {
    $this->menu['coke']->forceFill(['track_stock' => true, 'stock_qty' => 10])->save();
    $orders = app(OrderService::class);
    $payments = app(PaymentService::class);

    // One real order first.
    $real = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($real, $this->menu['coke']);
    $payments->payCash($real, Money::fromDecimal('12'), $this->cashier);

    // Then training mode on this device.
    app(TrainingMode::class)->set(true);
    $practice = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($practice, $this->menu['coke'], quantity: 3);
    $orders->sendToKitchen($practice, $this->cashier);
    $payment = $payments->payCash($practice->fresh(), Money::fromDecimal('50'), $this->cashier);
    $left = $orders->open(OrderType::DineIn, $this->cashier); // still open, in training

    expect($practice->fresh()->is_training)->toBeTrue()->and($payment->is_training)->toBeTrue()->and($real->fresh()->is_training)->toBeFalse()
        ->and($practice->fresh()->isPaid())->toBeTrue()
        ->and((float) $this->menu['coke']->fresh()->stock_qty)->toBe(9.0) // only the real order took stock
        ->and(StockMovement::query()->count())->toBe(1);

    $summary = app(DailySummaryService::class)->forDate(now()->toDateString());
    expect($summary['orders_paid'])->toBe(1)->and($summary['gross_sales'])->toBePesewas(1200)->and($summary['orders_open'])->toBe(0);
    expect(app(ReportService::class)->summary(now()->toDateString(), now()->toDateString())['gross_sales'])->toBe(1200);
    expect(app(CashService::class)->expected($this->session))->toBePesewas(10000 + 1200);
    expect(implode(' ', app(DayCloseService::class)->blockers(now()->toDateString())))->not->toContain('order'); // the open training order does not block the day (only the open till does)

    // Receipt and kitchen ticket say so.
    $this->actingAs($this->cashier);
    $this->get(route('pos.receipt', $practice))->assertOk()->assertSee('TRAINING');
    expect(\App\Modules\Kitchen\Models\Ticket::query()->where('order_id', $practice->id)->first()->is_training)->toBeTrue();
    $this->get('/pos/orders')->assertSee('Training');
});

it('needs a manager PIN to switch training mode on and off, and shows the banner', function () {
    $this->actingAs($this->cashier);
    $this->get('/pos')->assertOk()->assertDontSee('Training mode')->assertDontSee('Start training mode'); // cashiers cannot switch it

    $this->actingAs($this->manager);
    Livewire::test(OrderTypeScreen::class)
        ->assertSee('Start training mode')
        ->call('toggleTraining')->assertSet('approval.permission', 'pos.training')
        ->call('approve', '1001')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')->assertRedirect(route('pos.home'));
    expect(app(TrainingMode::class)->isOn())->toBeTrue();
});
