<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Services\FoodCostService;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

/*
 * Test report 9 Nov 2026, H7: the tax, item, category and food-cost reports read order lines with
 * withoutGlobalScopes(), which also drops Laravel's "not deleted" filter, so a line removed from an order
 * before payment was still counted as sold, and the tax report showed more tax than was charged.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $orders = app(OrderService::class);

    // Two Coca-Colas and a Bottled Water rung up; the water is taken off again before paying.
    $order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($order, $this->menu['coke'], quantity: 2);
    $orders->addItem($order, $this->menu['coke'], quantity: 3); // a second Coca-Cola line, also removed
    $water = $orders->addItem($order, $this->menu['water']);
    $order = $order->fresh();
    $orders->removeItem($order, $water, $this->cashier);
    $orders->removeItem($order->fresh(), $order->items()->where('quantity', 3)->sole(), $this->cashier);
    $this->order = $order->fresh();
    app(PaymentService::class)->payCash($this->order, $this->order->balanceDue(), $this->cashier);
    $this->day = $this->order->business_date instanceof DateTimeInterface ? $this->order->business_date->format('Y-m-d') : (string) $this->order->business_date;
    $this->reports = app(ReportService::class);
});

it('leaves removed lines out of the item and category reports', function () {
    $items = collect($this->reports->byItem($this->day, $this->day));
    expect($items->pluck('name')->all())->toBe(['Coca-Cola'])
        ->and($items->firstWhere('name', 'Coca-Cola')['quantity'])->toBe(2)
        ->and($items->sum('gross'))->toBe($this->order->total->amount)
        ->and(collect($this->reports->byCategory($this->day, $this->day))->sum('quantity'))->toBe(2);
});

it('reports exactly the tax that was charged', function () {
    $tax = $this->reports->tax($this->day, $this->day);
    expect(collect($tax['components'])->sum('tax'))->toBe($this->order->tax_total->amount)
        ->and($tax['taxable_net'] + $tax['exempt_net'] + $tax['tax_total'])->toBe($tax['gross'])
        ->and($tax['exempt_net'])->toBe(0); // the removed water was the only tax-free line
});

it('leaves removed lines out of the food cost report', function () {
    $dishes = collect(app(FoodCostService::class)->report($this->day, $this->day)['dishes']);
    expect($dishes->pluck('name')->all())->not->toContain('Bottled Water')
        ->and($dishes->sum('quantity'))->toBe(2);
});
