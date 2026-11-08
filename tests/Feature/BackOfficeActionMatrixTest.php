<?php

use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Filament\Pages\FeaturesPage;
use App\Modules\Settings\Filament\Pages\LicencePage;
use App\Modules\Settings\Filament\Pages\TillSettingsPage;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Resources\Staff\Pages\CreateStaff;
use App\Modules\Staff\Filament\Resources\Staff\Pages\EditStaff;
use App\Modules\Staff\Models\User;
use App\Support\Features\FeatureService;
use App\Support\Licence\LicenceService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Back-office action guard (E9.6 review L1): every role presses the sensitive back-office buttons added in
 * the Complete Edition, each in a fresh app, and the outcome is compared with who is approved to do it.
 * The checks on managers themselves (security settings, the retention period, who becomes a manager) are
 * the owner's alone (E9.6 review M1, M2).
 */
function backOfficeActionApproved(): array
{
    return [
        'change till security settings' => ['owner'],
        'set the customer retention period' => ['owner'],
        'switch a feature on' => ['owner'],
        'install a licence' => ['owner'],
        'export a customer\'s data' => ['owner', 'manager'],
        'remove a customer\'s details' => ['owner', 'manager'],
        'give someone the manager role' => ['owner'],
        'reset another manager\'s PIN' => ['owner'],
    ];
}

dataset('back office roles', array_map(fn (Role $r) => $r->value, Role::cases()));
dataset('back office actions', array_keys(backOfficeActionApproved()));

it('lets only approved roles press back-office buttons', function (string $action, string $role) {
    seedRoles();
    $branch = provisionTenant();
    $owner = User::factory()->forBranch($branch)->withPin('9999')->create(['name' => 'Owner']);
    $owner->assignRole(Role::Owner->value);
    $user = $owner;
    if ($role !== 'owner') {
        $user = User::factory()->forBranch($branch)->withPin('7777')->create(['name' => 'Probe']);
        $user->assignRole($role);
    }
    $otherManager = User::factory()->forBranch($branch)->withPin('2468')->create(['name' => 'Other manager']);
    $otherManager->assignRole(Role::Manager->value);
    installTestLicence('pro', ['tenant' => $branch->tenant_id]);
    $customer = app(CustomerService::class)->create(['name' => 'Ama Serwaa', 'phone' => '0241234567'], $owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($user);
    $settings = fn () => app(TillSettings::class);

    try {
        $done = match ($action) {
            'change till security settings' => (function () use ($settings) {
                Livewire::test(TillSettingsPage::class)->set('data.second_approval_day_close', true)->call('save');

                return $settings()->secondApprovalDayClose();
            })(),
            'set the customer retention period' => (function () use ($settings) {
                Livewire::test(TillSettingsPage::class)->set('data.customer_retention_months', 12)->call('save');

                return $settings()->customerRetentionMonths() === 12;
            })(),
            'switch a feature on' => (function () {
                Livewire::test(FeaturesPage::class)->call('toggle', 'recipes');

                return app(FeatureService::class)->isOn('recipes');
            })(),
            'install a licence' => (function () use ($branch) {
                Livewire::test(LicencePage::class)->set('code', testLicenceCode(['plan' => 'business', 'tenant' => $branch->tenant_id]))->call('install');
                app()->forgetScopedInstances();

                return app(LicenceService::class)->current()?->plan === 'business';
            })(),
            'export a customer\'s data' => (function () use ($customer) {
                Livewire::test(EditCustomer::class, ['record' => $customer->id])->callAction('exportData')->assertFileDownloaded();

                return true;
            })(),
            'remove a customer\'s details' => (function () use ($customer) {
                Livewire::test(EditCustomer::class, ['record' => $customer->id])->callAction('anonymise', data: ['reason' => 'She asked']);

                return $customer->fresh()->anonymised_at !== null;
            })(),
            'give someone the manager role' => (function () {
                Livewire::test(CreateStaff::class)->fillForm(['name' => 'New manager', 'roles' => [Role::Manager->value], 'is_active' => true])->call('create');

                return User::query()->where('name', 'New manager')->first()?->hasRole(Role::Manager->value) ?? false;
            })(),
            'reset another manager\'s PIN' => (function () use ($otherManager) {
                Livewire::test(EditStaff::class, ['record' => $otherManager->getRouteKey()])->callAction('resetPin', data: ['new_pin' => '5555']);

                return $otherManager->fresh()->verifyPin('5555');
            })(),
        };
    } catch (HttpException|Throwable) {
        $done = false;
    }

    expect($done)->toBe(in_array($role, backOfficeActionApproved()[$action], true), "{$role} → {$action}");
})->with('back office actions')->with('back office roles');
