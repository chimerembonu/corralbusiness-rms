<?php

use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Models\CashMovement;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;

/*
 * Security review M3: pay-outs had no limit and no PIN, so a cashier could empty the drawer with a
 * typed reason. Above the pay-out limit (Till settings, GH₵ 200 by default) a manager PIN is needed
 * and the movement records who approved it.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Abena']);
    $this->manager->assignRole(Role::Manager->value);
    $this->cash = app(CashService::class);
    $this->session = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('1000'), now()->toDateString());
});

it('lets a cashier pay out up to the limit, and needs a manager above it', function () {
    $small = $this->cash->payOut($this->session, Money::fromDecimal('200'), 'Supplier paid in cash', $this->cashier);
    expect($small->approved_by_id)->toBeNull();

    expect(fn () => $this->cash->payOut($this->session, Money::fromDecimal('200.01'), 'Bank deposit', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->cash->payOut($this->session, Money::fromDecimal('700'), 'Bank deposit', $this->cashier, null, $this->cashier))
        ->toThrow(ManagerPinRequiredException::class); // a cashier cannot approve for themselves
    expect(CashMovement::query()->count())->toBe(1)
        ->and($this->cash->expected($this->session->fresh()))->toBePesewas(80000);

    $big = $this->cash->payOut($this->session, Money::fromDecimal('700'), 'Bank deposit', $this->cashier, 'SLIP 4', $this->manager);
    expect($big->approved_by_id)->toBe($this->manager->id)
        ->and($big->approvedBy->name)->toBe('Abena')
        ->and($this->cash->expected($this->session->fresh()))->toBePesewas(10000);
});

it('needs a manager for every pay-out when the limit is set to zero', function () {
    app(SettingsService::class)->set(TillSettings::CASH_PAY_OUT_LIMIT, 0);

    expect(fn () => $this->cash->payOut($this->session, Money::fromDecimal('1'), 'Petty cash', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect($this->cash->payOut($this->session, Money::fromDecimal('1'), 'Petty cash', $this->cashier, null, $this->manager)->approved_by_id)->toBe($this->manager->id);
});

it('opens the manager keypad on the cash screen for a large pay-out and records the approver', function () {
    $this->actingAs($this->cashier);

    $screen = Livewire::test(CashScreen::class)
        ->call('openModal', 'pay_out')->set('amount', '500')->call('setReason', 'Bank deposit')->set('reference', 'SLIP 9')
        ->call('recordMovement')
        ->assertSet('approval.permission', 'payments.cash_pay_out_large')->assertSee('Pay out GH₵ 500.00 from the drawer');
    expect(CashMovement::query()->count())->toBe(0);

    $screen->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')
        ->assertSet('approval', null)->assertSet('modal', null)->assertSee('Pay out of GH₵ 500.00 saved');

    $movement = CashMovement::query()->sole();
    expect($movement->amount)->toBePesewas(50000)
        ->and($movement->user_id)->toBe($this->cashier->id)
        ->and($movement->approved_by_id)->toBe($this->manager->id)
        ->and($movement->reference)->toBe('SLIP 9');
});

it('counts every pay-out of the session towards the limit, so it cannot be split into small ones', function () {
    $this->cash->payOut($this->session, Money::fromDecimal('150'), 'Petty cash', $this->cashier);
    expect(fn () => $this->cash->payOut($this->session->fresh(), Money::fromDecimal('150'), 'Petty cash', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class); // 150 + 150 = 300 > 200
    expect(CashMovement::query()->count())->toBe(1);

    $this->cash->payOut($this->session->fresh(), Money::fromDecimal('150'), 'Petty cash', $this->cashier, null, $this->manager);
    expect($this->session->fresh()->pay_outs)->toBePesewas(30000);
});

it('counts pay-outs across every session of the till on the same day, so cashing up and reopening does not reset the limit', function () {
    $this->cash->payOut($this->session, Money::fromDecimal('200'), 'Petty cash', $this->cashier);
    $this->cash->close($this->session->fresh(), Money::fromDecimal('800'), $this->cashier); // perfect count: no variance, no PIN
    $again = $this->cash->open($this->till, $this->cashier, Money::fromDecimal('800'), now()->toDateString());

    expect(fn () => $this->cash->payOut($again, Money::fromDecimal('1'), 'Petty cash', $this->cashier))
        ->toThrow(ManagerPinRequiredException::class);
    expect(CashMovement::query()->count())->toBe(1);

    $this->cash->payOut($again->fresh(), Money::fromDecimal('100'), 'Petty cash', $this->cashier, null, $this->manager);
    expect(CashMovement::query()->count())->toBe(2);

    // A device without a till: that cashier's own sessions add up the same way.
    $other = User::factory()->forBranch($this->branch)->withPin('2222')->create(['name' => 'Ama']);
    $other->assignRole(Role::Cashier->value);
    $loose = $this->cash->open(null, $other, Money::fromDecimal('500'), now()->toDateString());
    $this->cash->payOut($loose, Money::fromDecimal('200'), 'Petty cash', $other);
    $this->cash->close($loose->fresh(), Money::fromDecimal('300'), $other);
    $loose2 = $this->cash->open(null, $other, Money::fromDecimal('300'), now()->toDateString());
    expect(fn () => $this->cash->payOut($loose2, Money::fromDecimal('1'), 'Petty cash', $other))->toThrow(ManagerPinRequiredException::class);
});
