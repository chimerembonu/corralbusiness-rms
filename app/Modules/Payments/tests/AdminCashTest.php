<?php

use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Filament\Resources\DayCloses\Pages\ListDayCloses;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Pos\Filament\Resources\Tills\Pages\CreateTill;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Filament\Pages\TillSettingsPage;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
});

it('manages tills in the back office', function () {
    Livewire::test(CreateTill::class)
        ->fillForm(['name' => 'Till 7 – Garden', 'code' => 'T7', 'is_active' => true])
        ->call('create')->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    expect(Till::query()->where('code', 'T7')->exists())->toBeTrue();
    $this->get('/admin/tills')->assertOk()->assertSee('Till 7 – Garden');
});

it('lists cashier sessions and Z reports, and lets a manager reopen a day', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $session = app(CashService::class)->open($till, $this->owner, Money::fromDecimal('500'), now()->toDateString());
    app(CashService::class)->payOut($session, Money::fromDecimal('25'), 'Petty cash', $this->owner);
    app(CashService::class)->close($session, Money::fromDecimal('480'), $this->owner, [], 'Short coins', $this->owner);
    $close = app(DayCloseService::class)->close(now()->toDateString(), $this->owner);

    $this->get('/admin/cashier-sessions')->assertOk()->assertSee('S0001')->assertSee('Till 1')->assertSee('500.00')->assertSee('+GH₵ 5.00');
    $this->get('/admin/cashier-sessions/'.$session->id)->assertOk()->assertSee('Petty cash')->assertSee('Short coins')->assertSee('475.00');
    $this->get('/admin/day-closes')->assertOk()->assertSee('Z0001')->assertSee('Closed');
    $this->get('/admin/day-closes/'.$close->id)->assertOk()->assertSee('Z report Z0001')->assertSee('Chimere');

    Livewire::test(ListDayCloses::class)
        ->callAction(TestAction::make('reopen')->table($close), ['reason' => 'Forgot a table'])
        ->assertHasNoErrors();
    expect($close->fresh()->isClosed())->toBeFalse();
});

it('saves the cash settings', function () {
    Livewire::test(TillSettingsPage::class)
        ->assertFormSet(['default_float' => '500.00', 'variance_tolerance' => '5.00', 'require_session' => true])
        ->fillForm(['default_float' => '800', 'variance_tolerance' => '10', 'pay_out_reasons' => ['Fuel'], 'require_session' => false])
        ->call('save')->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    $settings = app(TillSettings::class);
    expect($settings->defaultFloat())->toBe(80000)
        ->and($settings->varianceTolerance())->toBe(1000)
        ->and($settings->payOutReasons())->toBe(['Fuel'])
        ->and($settings->requireSession())->toBeFalse();
});
