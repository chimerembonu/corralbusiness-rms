<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Services\FoodCostService;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/*
 * Test report 9 Nov 2026, H10: the reports loaded every order and line of the range into memory at once; a
 * year of trading needed about 1.4 GB, so long ranges failed on the store server (256 MB). They now add up a
 * page at a time. Checked on a year of seeded trading (34,900 orders, 120,800 lines, 34,700 payments): every
 * report identical to the old code, peak memory 108 MB instead of 1,383 MB.
 *
 * Here the page size is shrunk to 2, so a handful of orders crosses many pages, and the totals are checked
 * against the orders themselves.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $orders = app(OrderService::class);
    $payments = app(PaymentService::class);

    // Seven sales over three days and both order types, paid by cash and MoMo; one order cancelled.
    $today = now()->startOfDay();
    foreach (range(1, 7) as $i) {
        $this->travelTo($today->copy()->subDays($i % 3)->setTime(9 + $i, 15));
        $order = $orders->open($i % 2 ? OrderType::Takeaway : OrderType::Delivery, $this->cashier);
        $orders->addItem($order, $this->menu['coke'], quantity: $i);
        $orders->addItem($order, $this->menu['water']);
        $order = $order->fresh();
        $i % 2
            ? $payments->payCash($order, $order->balanceDue(), $this->cashier)
            : $payments->record($order, PaymentMethod::MobileMoney, $order->balanceDue(), $this->cashier, provider: MomoProvider::Mtn, reference: 'R'.$i);
    }
    $void = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($void, $this->menu['coke'], quantity: 9);
    $orders->void($void->fresh(), 'Test', $this->cashier);
    $this->travelBack();

    $this->from = now()->subDays(5)->toDateString();
    $this->to = now()->addDay()->toDateString();
    $this->sales = Order::query()->where('status', 'paid')->get();
    ReportService::$page = 2;
});

afterEach(fn () => ReportService::$page = 1000);

it('adds up every order and line across pages', function () {
    $r = app(ReportService::class);
    $gross = (int) $this->sales->sum(fn ($o) => $o->total->amount);
    $tax = (int) $this->sales->sum(fn ($o) => $o->tax_total->amount);
    $cokes = 1 + 2 + 3 + 4 + 5 + 6 + 7;

    expect($r->summary($this->from, $this->to))->toMatchArray(['orders' => 7, 'orders_void' => 1, 'gross_sales' => $gross, 'tax_total' => $tax])
        ->and(collect($r->byDay($this->from, $this->to))->sum('gross'))->toBe($gross)
        ->and(collect($r->byDay($this->from, $this->to))->where('orders', '>', 0))->toHaveCount(3)
        ->and(collect($r->byHour($this->from, $this->to))->sum('orders'))->toBe(7)
        ->and(collect($r->byType($this->from, $this->to))->pluck('orders', 'type')->all())->toEqual(['takeaway' => 4, 'delivery' => 3])
        ->and(collect($r->byItem($this->from, $this->to))->pluck('quantity', 'name')->all())->toEqual(['Coca-Cola' => $cokes, 'Bottled Water' => 7])
        ->and(collect($r->byItem($this->from, $this->to))->sum('gross'))->toBe($gross)
        ->and(collect($r->byCategory($this->from, $this->to))->sum('quantity'))->toBe($cokes + 7)
        ->and($r->tax($this->from, $this->to))->toMatchArray(['tax_total' => $tax, 'gross' => $gross])
        ->and(collect($r->tax($this->from, $this->to)['components'])->sum('tax'))->toBe($tax)
        ->and(collect(app(FoodCostService::class)->report($this->from, $this->to)['dishes'])->sum('quantity'))->toBe($cokes + 7);

    $methods = collect($r->byPaymentMethod($this->from, $this->to))->keyBy('method');
    expect($methods['cash']['count'])->toBe(4)
        ->and($methods['momo:mtn']['count'])->toBe(3)
        ->and($methods->sum('total'))->toBe($gross);
});

it('reads a year without loading it all at once', function () {
    // The query plan, not the data: sales are read by id in pages, never as one get().
    $queries = collect();
    DB::listen(fn ($q) => $queries->push($q->sql));
    app(ReportService::class)->byItem($this->from, $this->to);

    expect($queries->filter(fn ($sql) => preg_match('/from [`"]orders[`"]/', $sql) && str_contains($sql, 'limit 2'))->count())->toBeGreaterThan(2);
});
