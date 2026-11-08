<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Models\DayClose;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Livewire\OrdersList;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Filament\Pages\TillSettingsPage;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\SecondApprovalRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Complete Edition E9.4 — optional second-person approval: when Till settings ask for it, a big refund or
 * closing the day needs two different managers' PINs. Off by default.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->abena = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Abena']);
    $this->abena->assignRole(Role::Manager->value);
    $this->yaw = User::factory()->forBranch($this->branch)->withPin('3579')->create(['name' => 'Yaw']);
    $this->yaw->assignRole(Role::Manager->value);
    $this->session = app(CashService::class)->open($this->till, $this->cashier, Money::fromDecimal('100'), now()->toDateString());
});

function paidCokes(int $count)
{
    $order = app(OrderService::class)->open(OrderType::Takeaway, test()->cashier);
    app(OrderService::class)->addItem($order, test()->menu['coke'], quantity: $count); // GH₵ 12.00 each

    return app(PaymentService::class)->payCash($order, Money::fromDecimal((string) (12 * $count)), test()->cashier);
}

it('asks for one manager by default, and two for a refund above the limit set in Till settings', function () {
    $this->actingAs($this->cashier);
    $small = paidCokes(1);
    Livewire::test(OrdersList::class)->call('startRefund', $small->id)->set('refund.reason', 'Cold drink')->call('confirmRefund')
        ->assertSet('approval.second', false)->call('approve', '2468');
    expect($small->fresh()->isRefunded())->toBeTrue()->and($small->fresh()->refund_second_approved_by_id)->toBeNull();

    app(SettingsService::class)->set(TillSettings::SECOND_APPROVAL_REFUND_ABOVE, 5000); // GH₵ 50
    expect(app(PaymentService::class)->needsSecondApproval(paidCokes(4)))->toBeFalse(); // GH₵ 48

    $big = paidCokes(5); // GH₵ 60
    $screen = Livewire::test(OrdersList::class)->call('startRefund', $big->id)->set('refund.reason', 'Wrong order')->call('confirmRefund')
        ->assertSet('approval.second', true)->assertSee('Two managers must approve this')
        ->call('approve', '2468')->assertSee('Approved by Abena. A second, different manager now types their PIN.');
    expect($big->fresh()->isRefunded())->toBeFalse();

    $screen->call('approve', '2468')->assertSet('approval.error', 'The second approval must come from a different manager.')
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.');
    expect($big->fresh()->isRefunded())->toBeFalse();

    $screen->call('approve', '3579')->assertSet('approval', null);
    expect($big->fresh())->isRefunded()->toBeTrue()->refunded_by_id->toBe($this->abena->id)->refund_second_approved_by_id->toBe($this->yaw->id)
        ->and(Activity::query()->where('description', 'refund_second_approval')->where('causer_id', $this->yaw->id)->exists())->toBeTrue();
});

it('enforces the second approval in the service itself, not only on the screen', function () {
    app(SettingsService::class)->set(TillSettings::SECOND_APPROVAL_REFUND_ABOVE, 1000);
    $payment = paidCokes(1);
    $payments = app(PaymentService::class);

    expect(fn () => $payments->refund($payment, 'Cold', $this->abena, $this->cashier))->toThrow(SecondApprovalRequiredException::class, 'A second manager must confirm this.')
        ->and(fn () => $payments->refund($payment, 'Cold', $this->abena, $this->cashier, $this->abena))->toThrow(SecondApprovalRequiredException::class, 'different manager')
        ->and(fn () => $payments->refund($payment, 'Cold', $this->abena, $this->cashier, $this->cashier))->toThrow(SecondApprovalRequiredException::class);
    expect($payment->fresh()->isRefunded())->toBeFalse();

    $payments->refund($payment, 'Cold', $this->abena, $this->cashier, $this->yaw);
    expect($payment->fresh()->isRefunded())->toBeTrue();
});

it('asks two managers to close the day when Till settings say so, and shows both on the Z report', function () {
    app(CashService::class)->close($this->session, Money::fromDecimal('100'), $this->abena);
    app(SettingsService::class)->set(TillSettings::SECOND_APPROVAL_DAY_CLOSE, true);
    expect(fn () => app(DayCloseService::class)->close(now()->toDateString(), $this->abena))->toThrow(SecondApprovalRequiredException::class);

    $this->actingAs($this->abena);
    Livewire::test(CashScreen::class)->call('openModal', 'close_day')->call('closeDay')
        ->assertSet('approval.second', true)
        ->call('approve', '2468')->assertSee('Approved by Abena')
        ->call('approve', '3579')->assertSee('Z report Z0001 printed');

    $close = DayClose::query()->first();
    expect($close->approved_by_id)->toBe($this->abena->id)->and($close->second_approved_by_id)->toBe($this->yaw->id);
    $this->get(route('pos.cash.report', ['date' => now()->toDateString()]))->assertSee('Confirmed by')->assertSee('Yaw');
});

it('is set on Till settings → Security, off by default', function () {
    $settings = app(TillSettings::class);
    expect($settings->secondApprovalRefundAbove())->toBe(0)->and($settings->secondApprovalDayClose())->toBeFalse();

    $owner = User::factory()->forBranch($this->branch)->create();
    $owner->assignRole(Role::Owner->value);
    $this->actingAs($owner);
    Livewire::test(TillSettingsPage::class)->assertSee('Refunds above this need two managers')
        ->set('data.second_approval_refund_above', '200')->set('data.second_approval_day_close', true)->call('save')->assertHasNoErrors();
    expect(app(TillSettings::class)->secondApprovalRefundAbove())->toBe(20000)->and(app(TillSettings::class)->secondApprovalDayClose())->toBeTrue();
});
