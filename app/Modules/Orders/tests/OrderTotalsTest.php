<?php

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\InvalidModifierSelectionException;
use App\Modules\Orders\Exceptions\ItemNotOrderableException;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Services\TaxService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->orders = app(OrderService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
});

it('prices a line with a size and modifiers and adds Ghana 2026 taxes on top', function () {
    $line = $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular'], 2, [$this->menu['chicken']->id => 1], 'no pepper', $this->cashier);

    expect($line->unit_price)->toBePesewas(4500)
        ->and($line->modifiers_total)->toBePesewas(1500)
        ->and($line->line_subtotal)->toBePesewas(12000)
        ->and($line->tax_total)->toBePesewas(2400)       // NHIL 300 + GETFund 300 + VAT 1800
        ->and($line->line_total)->toBePesewas(14400)
        ->and($line->modifiers()->count())->toBe(1)
        ->and($line->notes)->toBe('no pepper');

    $order = $this->order->fresh();
    expect($order->subtotal)->toBePesewas(12000)
        ->and($order->tax_total)->toBePesewas(2400)
        ->and($order->total)->toBePesewas(14400)
        ->and(collect($order->tax_lines)->pluck('amount', 'code')->all())->toBe(['NHIL' => 300, 'GETFUND' => 300, 'VAT' => 1800]);
});

it('counts modifier quantities and leaves non-taxable items untaxed', function () {
    $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['large'], 1, [$this->menu['beef']->id => 1, $this->menu['plantain']->id => 2]);
    $this->orders->addItem($this->order, $this->menu['water'], null, 3);

    $order = $this->order->fresh();
    // Jollof large 60 + beef 15 + 2 × plantain 8 = 91.00 → NHIL 2.28 + GETFund 2.28 + VAT 13.65 = 18.21 (each rounded)
    // water 3 × 5 = 15.00 untaxed
    expect($order->subtotal)->toBePesewas(9100 + 1500)
        ->and($order->tax_total)->toBePesewas(1821)
        ->and($order->total)->toBePesewas(10921 + 1500);
});

it('spreads an order discount across lines before tax (threshold needs a manager)', function () {
    $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular'], 2, [$this->menu['chicken']->id => 1]); // 120.00
    $this->orders->addItem($this->order, $this->menu['water'], null, 1);                                                      // 5.00

    $order = $this->orders->applyDiscount($this->order, DiscountType::Percent, 1000, 'Staff meal', $this->cashier);

    expect($order->discount_total)->toBePesewas(1250)
        ->and($order->tax_total)->toBePesewas(2160)     // 20 % of (120 − 12) = 21.60
        ->and($order->total)->toBePesewas(12960 + 450)
        ->and($order->items->firstWhere('name', 'Jollof Rice')->discount_amount)->toBePesewas(1200)
        ->and($order->items->firstWhere('name', 'Bottled Water')->discount_amount)->toBePesewas(50);

    // 20.00 off 125.00 is 16 %, above the 10 % manager threshold → a manager approves it.
    seedRoles();
    $manager = User::factory()->forBranch($this->branch)->create();
    $manager->assignRole(Role::Manager->value);
    $order = $this->orders->applyDiscount($order, DiscountType::Fixed, 2000, 'Voucher', $this->cashier, $manager);
    // 20.00 off is split 19.20 (jollof) / 0.80 (water); tax on 100.80 = 20.16 → 120.96 + 4.20
    expect($order->discount_total)->toBePesewas(2000)
        ->and($order->total)->toBePesewas(12096 + 420);

    $order = $this->orders->applyDiscount($order, null);
    expect($order->discount_total)->toBePesewas(0)->and($order->total)->toBePesewas(14900);
});

it('extracts tax from tax-inclusive menu prices', function () {
    app(TaxService::class)->setPricesIncludeTax(true);

    $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular'], 2, [$this->menu['chicken']->id => 1]); // 120.00 incl.
    $this->orders->addItem($this->order, $this->menu['water']);

    $order = $this->order->fresh();
    expect($order->prices_include_tax)->toBeTrue()
        ->and($order->subtotal)->toBePesewas(12500)
        ->and($order->tax_total)->toBePesewas(2000)     // 120 = 100 net + 20 tax
        ->and($order->total)->toBePesewas(12500)
        ->and($order->netTotal())->toBePesewas(10500);
});

it('enforces required and maximum modifier choices and sizes', function () {
    expect(fn () => $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular']))
        ->toThrow(InvalidModifierSelectionException::class);
    expect(fn () => $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1, $this->menu['beef']->id => 1]))
        ->toThrow(InvalidModifierSelectionException::class);
    expect(fn () => $this->orders->addItem($this->order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1, $this->menu['plantain']->id => 4]))
        ->toThrow(InvalidModifierSelectionException::class);
    expect(fn () => $this->orders->addItem($this->order, $this->menu['jollof'], null, 1, [$this->menu['chicken']->id => 1]))
        ->toThrow(InvalidModifierSelectionException::class);

    expect($this->order->fresh()->items)->toHaveCount(0);
});

it('refuses sold-out items', function () {
    $this->menu['coke']->update(['is_sold_out' => true]);

    expect(fn () => $this->orders->addItem($this->order, $this->menu['coke']))->toThrow(ItemNotOrderableException::class);
});

it('recalculates when quantities change or lines are removed', function () {
    $line = $this->orders->addItem($this->order, $this->menu['coke'], null, 1);
    $this->orders->changeQuantity($this->order, $line, 3);
    expect($this->order->fresh()->total)->toBePesewas(3600);

    $this->orders->changeQuantity($this->order, $line->fresh(), 0);
    expect($this->order->fresh()->items)->toHaveCount(0)
        ->and($this->order->fresh()->total)->toBePesewas(0);
});
