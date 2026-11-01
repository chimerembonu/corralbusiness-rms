<?php

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);
    $this->reports = app(ReportService::class);

    // Day 1, 12:00: dine-in, 2 coke + 1 water, 10 % discount, paid cash.
    Carbon::setTestNow('2026-11-02 12:00:00');
    $a = $this->orders->open(OrderType::DineIn, $this->cashier, covers: 3);
    $this->orders->addItem($a, $this->menu['coke'], quantity: 2);   // 24.00 gross (20 net + 4 tax)
    $this->orders->addItem($a, $this->menu['water']);               // 5.00, tax-free
    $this->orders->applyDiscount($a, DiscountType::Percent, 1000, 'Loyal customer', $this->cashier); // 10 % of 25 = 2.50
    $this->payments->payCash($a->fresh(), Money::fromDecimal('30'), $this->cashier);

    // Day 1, 19:30: takeaway, 1 coke by MoMo; then a voided takeaway.
    Carbon::setTestNow('2026-11-02 19:30:00');
    $b = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($b, $this->menu['coke']);
    $this->payments->payMobileMoney($b, MomoProvider::Mtn, Money::fromDecimal('12'), 'MP1', $this->cashier);
    $void = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($void, $this->menu['coke'], quantity: 4);
    $this->orders->void($void, 'Customer left', $this->manager);

    // Day 2: one takeaway coke, paid cash, then refunded.
    Carbon::setTestNow('2026-11-03 13:00:00');
    $c = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($c, $this->menu['coke']);
    $payment = $this->payments->payCash($c, Money::fromDecimal('12'), $this->cashier);
    $this->payments->refund($payment, 'Flat', $this->manager, $this->cashier);
    Carbon::setTestNow('2026-11-03 14:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('summarises a date range and breaks it down by day, hour and type', function () {
    $s = $this->reports->summary('2026-11-02', '2026-11-03');
    // Order A: subtotal 25, discount 2.50 → 22.50; coke 20 net → 18 net after discount (10 %) → tax 3.60 → gross 22.50? let the engine speak:
    $a = $this->orders->open(OrderType::Takeaway, $this->cashier); // untouched open order must not count
    $this->orders->addItem($a, $this->menu['coke']);

    expect($s['orders'])->toBe(2)
        ->and($s['orders_void'])->toBe(1)->and($s['void_value'])->toBe(4800)
        ->and($s['orders_refunded'])->toBe(1)->and($s['refund_total'])->toBe(1200)
        ->and($s['covers'])->toBe(4)
        ->and($s['discount_total'])->toBe(250)
        ->and($s['gross_sales'])->toBe($s['net_sales'] + $s['tax_total'])
        ->and($s['average_order'])->toBe(intdiv($s['gross_sales'], 2))
        ->and($s['days'])->toBe(2)->and($s['average_per_day'])->toBe(intdiv($s['gross_sales'], 2));

    $days = $this->reports->byDay('2026-11-02', '2026-11-03');
    expect($days)->toHaveCount(2)->and($days[0]['orders'])->toBe(2)->and($days[1]['orders'])->toBe(0) // day 2's order was refunded
        ->and($days[0]['gross'])->toBe($s['gross_sales'])->and($days[0]['discounts'])->toBe(250);

    $hours = $this->reports->byHour('2026-11-02', '2026-11-02');
    expect(array_column($hours, 'hour'))->toBe([12, 19])->and($hours[0]['label'])->toBe('12:00–13:00')->and($hours[1]['gross'])->toBe(1200);

    $types = $this->reports->byType('2026-11-02', '2026-11-02');
    expect(array_column($types, 'type'))->toBe(['dine_in', 'takeaway'])->and($types[1]['gross'])->toBe(1200)->and($types[1]['average'])->toBe(1200);
});

it('breaks sales down by item, category, payment method and staff', function () {
    $items = $this->reports->byItem('2026-11-02', '2026-11-02');
    expect(array_column($items, 'name'))->toBe(['Coca-Cola', 'Bottled Water'])
        ->and($items[0]['quantity'])->toBe(3)->and($items[0]['category'])->toBe('Drinks')
        ->and($items[1]['net'])->toBe($items[1]['gross']) // water is tax-free
        ->and(round($items[0]['share'] + $items[1]['share']))->toBe(100.0);

    $categories = $this->reports->byCategory('2026-11-02', '2026-11-02');
    expect($categories)->toHaveCount(1)->and($categories[0]['category'])->toBe('Drinks')->and($categories[0]['quantity'])->toBe(4)->and($categories[0]['share'])->toBe(100.0);

    $methods = $this->reports->byPaymentMethod('2026-11-02', '2026-11-03');
    $cash = collect($methods)->firstWhere('method', 'cash');
    $momo = collect($methods)->firstWhere('method', 'momo:mtn');
    expect($cash['count'])->toBe(1)->and($cash['refunded'])->toBe(1200)->and($momo['label'])->toBe('Mobile Money (MTN MoMo)')->and($momo['total'])->toBe(1200);

    $staff = $this->reports->byStaff('2026-11-02', '2026-11-03');
    expect($staff[0]['name'])->toBe('Kofi')->and($staff[0]['orders'])->toBe(2)->and($staff[0]['voids'])->toBe(1)->and($staff[0]['payments'])->toBe(2);
});

it('lists voids, discounts and refunds with who did them, and reports tax per component', function () {
    $v = $this->reports->voidsAndDiscounts('2026-11-02', '2026-11-03');
    expect($v['voided_orders'])->toHaveCount(1)->and($v['voided_orders'][0]['by'])->toBe('Akosua')->and($v['voided_orders'][0]['detail'])->toBe('Customer left')->and($v['totals']['voided_orders'])->toBe(4800)
        ->and($v['discounts'])->toHaveCount(1)->and($v['discounts'][0]['value'])->toBe(250)->and($v['discounts'][0]['detail'])->toContain('Loyal customer')
        ->and($v['refunds'])->toHaveCount(1)->and($v['refunds'][0]['by'])->toBe('Akosua')->and($v['totals']['refunds'])->toBe(1200)
        ->and($v['voided_lines'])->toHaveCount(0);

    $tax = $this->reports->tax('2026-11-02', '2026-11-02');
    $s = $this->reports->summary('2026-11-02', '2026-11-02');
    expect($tax['gross'])->toBe($s['gross_sales'])
        ->and($tax['tax_total'])->toBe($s['tax_total'])
        ->and($tax['exempt_net'])->toBe(450) // the bottled water, 5.00 less its share of the 10 % discount
        ->and(array_column($tax['components'], 'code'))->toContain('VAT')
        ->and((int) array_sum(array_column($tax['components'], 'tax')))->toBe($s['tax_total'])
        ->and(collect($tax['components'])->firstWhere('code', 'VAT')['rate'])->toBe('15%')
        ->and($tax['taxable_net'] + $tax['exempt_net'])->toBe($s['net_sales']);
});
