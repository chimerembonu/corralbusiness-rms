<?php

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\DiscountException;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Printing\Documents\ReceiptDocument;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tax\TaxIdentificationNumber;
use Livewire\Livewire;

/*
 * Compliance assessment (VAT Act 2025): a business customer can have their TIN printed on the receipt
 * to claim input VAT. Optional, validated, and taken from the customer record when one is attached.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->orders = app(OrderService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke'], null, 1, [], null, $this->cashier);
});

function receiptText($order): array
{
    return collect(ReceiptDocument::fromOrder($order->fresh()->load('items.modifiers'))->lines())
        ->map(fn ($l) => trim(($l['text'] ?? '').' '.($l['left'] ?? '').' '.($l['right'] ?? '')))->all();
}

it('accepts the GRA TIN and the Ghana Card number, however they are typed', function () {
    expect(TaxIdentificationNumber::normalise(' c0012345678 '))->toBe('C0012345678')
        ->and(TaxIdentificationNumber::normalise('gha 123456789 0'))->toBe('GHA-123456789-0')
        ->and(TaxIdentificationNumber::normalise('GHA-123456789-0'))->toBe('GHA-123456789-0')
        ->and(TaxIdentificationNumber::normalise(''))->toBeNull()
        ->and(TaxIdentificationNumber::normalise('12345'))->toBeFalse()
        ->and(TaxIdentificationNumber::normalise('C001234567'))->toBeFalse();
});

it('prints the customer TIN on the receipt only when one was given', function () {
    expect(collect(receiptText($this->order))->filter(fn ($l) => str_contains($l, 'Customer TIN'))->all())->toBe([]);

    $this->orders->setCustomerTin($this->order, 'c0012345678', $this->cashier);
    app(CashService::class)->open(Till::create(['name' => 'T1', 'code' => 'T1']), $this->cashier, Money::of(1000), $this->orders->businessDate());
    app(PaymentService::class)->payCash($this->order->fresh(), Money::fromDecimal('20'), $this->cashier);

    expect(collect(receiptText($this->order))->contains(fn ($l) => str_contains($l, 'Customer TIN') && str_contains($l, 'C0012345678')))->toBeTrue();
});

it('refuses a TIN in the wrong format and a TIN on a cancelled order', function () {
    expect(fn () => $this->orders->setCustomerTin($this->order, 'ABC', $this->cashier))->toThrow(DiscountException::class, 'not a valid TIN');
    expect($this->order->fresh()->customer_tin)->toBeNull();

    $this->order->forceFill(['status' => OrderStatus::Void])->save();
    expect(fn () => $this->orders->setCustomerTin($this->order->fresh(), 'C0012345678', $this->cashier))->toThrow(OrderLockedException::class);
});

it('takes the TIN from the customer record when the customer is attached', function () {
    $customer = Customer::create(['name' => 'Accra Traders Ltd', 'phone' => '0302000000', 'tin' => 'C0099999999']);
    app(CustomerService::class)->attach($this->order, $customer, $this->cashier);

    expect($this->order->fresh()->customer_tin)->toBe('C0099999999');
});

it('lets the cashier add it on the till', function () {
    $this->actingAs($this->cashier);
    Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('openModal', 'tin')->assertSet('modal', 'tin')
        ->set('customerTin', 'nonsense')->call('saveCustomerTin')->assertSet('modal', 'tin')->assertSee('not a valid TIN')
        ->set('customerTin', 'gha-123456789-0')->call('saveCustomerTin')->assertSet('modal', null)->assertSee('GHA-123456789-0');

    expect($this->order->fresh()->customer_tin)->toBe('GHA-123456789-0');
});
