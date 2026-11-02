<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;

/*
 * Re-review: void and discount reasons from Till settings were pasted raw into a wire:click
 * expression, so a manager could plant JavaScript that ran on every cashier's screen. Reasons are now
 * emitted as JavaScript string literals.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->actingAs($this->cashier);
    $this->hostile = "Mistake'); fetch('/x'); ('";
    app(SettingsService::class)->set(TillSettings::VOID_REASONS, [$this->hostile]);
    app(SettingsService::class)->set(TillSettings::DISCOUNT_REASONS, [$this->hostile]);
    app(SettingsService::class)->set(TillSettings::CASH_PAY_OUT_REASONS, [$this->hostile]);
});

it('renders a hostile void or discount reason as a harmless string literal', function () {
    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::Takeaway, $this->cashier);
    $line = $orders->addItem($order, $this->menu['coke']);
    $orders->sendToKitchen($order, $this->cashier);

    $html = Livewire::test(OrderScreen::class, ['order' => $order])->call('startVoid', $line->id)->html();
    expect($html)->not->toContain("chooseVoidReason('Mistake');")
        ->and($html)->toContain('chooseVoidReason(');
    expect(preg_match('/chooseVoidReason\\(([^)]*)\\)/', $html, $m))->toBe(1)
        ->and($m[1])->not->toContain("');"); // the quote inside the reason is escaped, so the expression cannot be closed early

    $html = Livewire::test(OrderScreen::class, ['order' => $order])->call('startDiscount')->html();
    expect($html)->not->toContain("chooseDiscountReason('Mistake');");

    // Choosing it still works as plain text.
    Livewire::test(OrderScreen::class, ['order' => $order])->call('startVoid', $line->id)->call('chooseVoidReason', $this->hostile)->assertSet('void.reason', $this->hostile);
});

it('does the same on the cash screen', function () {
    app(CashService::class)->open(Till::create(['name' => 'T1', 'code' => 'T1']), $this->cashier, Money::fromDecimal('100'), now()->toDateString());
    $html = Livewire::test(CashScreen::class)->call('openModal', 'pay_out')->html();
    expect($html)->not->toContain("setReason('Mistake');");
});
