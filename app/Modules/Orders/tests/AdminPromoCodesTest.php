<?php

use App\Modules\Orders\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Filament\Pages\TillSettingsPage;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    PromoCode::create(['code' => 'WELCOME10', 'name' => 'Welcome', 'type' => 'percent', 'value' => 1000]);
    $this->actingAs($this->owner);
});

it('lists and creates promo codes in the back office', function () {
    $this->get('/admin/promo-codes')->assertOk()->assertSee('WELCOME10')->assertSee('10%');

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'friday20', 'name' => 'Friday', 'type' => 'fixed', 'value' => '20', 'min_order' => '150', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    $promo = PromoCode::query()->where('code', 'FRIDAY20')->first();
    expect($promo)->not->toBeNull()
        ->and($promo->value)->toBe(2000)
        ->and($promo->min_order)->toBe(15000)
        ->and($promo->describe())->toBe('GH₵ 20.00');
});

it('saves till settings for the branch', function () {
    $this->get('/admin/till-settings-page')->assertOk()->assertSee('Till settings');

    Livewire::test(TillSettingsPage::class)
        ->assertFormSet(['discount_threshold_percent' => 10.0, 'tips_enabled' => true])
        ->fillForm(['service_charge_percent' => '12.5', 'service_charge_types' => ['dine_in', 'delivery'], 'discount_threshold_percent' => '15', 'void_reasons' => ['Oops'], 'tips_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    $settings = app(TillSettings::class);
    expect($settings->serviceChargeBp())->toBe(1250)
        ->and($settings->serviceChargeOrderTypes())->toBe(['dine_in', 'delivery'])
        ->and($settings->discountThresholdBp())->toBe(1500)
        ->and($settings->voidReasons())->toBe(['Oops'])
        ->and($settings->tipsEnabled())->toBeFalse()
        ->and(app(SettingsService::class)->get(TillSettings::COURSES))->toBe(['Starters', 'Mains', 'Desserts']);
});
