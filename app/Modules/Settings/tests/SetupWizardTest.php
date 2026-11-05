<?php

use App\Modules\Menu\Models\Item;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Livewire\SetupWizard;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\TaxComponent;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SetupService;
use App\Modules\Settings\Services\TaxService;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Errors\UserFacingException;
use App\Support\Tenancy\Tenancy;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Complete Edition E1.3 — the first-run setup wizard at /setup (no command line needed).
 */

it('opens the wizard on every screen of a fresh install, and never again once set up', function () {
    $this->get('/')->assertRedirect('/setup');
    $this->get('/pos/login')->assertRedirect('/setup');
    $this->get('/admin/login')->assertRedirect('/setup');
    $this->get('/setup')->assertOk()->assertSee('Welcome to CorralBusiness – RMS');
    $this->get('/setup/templates/menu')->assertOk()->assertDownload('corralbusiness-menu-template.xlsx');

    provisionTenant();

    $this->get('/')->assertOk();
    $this->get('/setup')->assertRedirect(route('home'));
    $this->get('/setup/templates/menu')->assertNotFound();
});

it('sets up the restaurant, owner, taxes, receipt, tills, menu and staff in one go and signs the owner in', function () {
    $menu = setupCsv('menu', [
        ['Rice', 'Jollof Rice', 'JOL', '45.00', '', '', '', 'yes', '', 'no', ''],
        ['Drinks', 'Sobolo', 'SOB', '10.00', '', '', '', 'yes', '', 'no', ''],
    ]);
    $staff = setupCsv('staff', [['Yaw Asante', '024 100 0003', 'waiter', '2001', 'Waiter', '', '']]);

    walkSetup(['menuFile' => $menu, 'staffFile' => $staff])
        ->assertSee('Mama Efua Chop Bar')->assertSee('Front counter, Bar')
        ->call('finish')->assertHasNoErrors()->assertSet('step', 'done')
        ->assertSee('Mama Efua Chop Bar is ready')->assertSee('Menu: 2 new')->assertSee('Staff: 1 new')
        ->assertSet('password', '')->assertSet('pin', '');

    $tenant = Tenant::query()->sole();
    $branch = Branch::query()->withoutGlobalScopes()->sole();
    expect($tenant->name)->toBe('Mama Efua Chop Bar')
        ->and($branch->name)->toBe('Osu')->and($branch->address)->toBe('12 Oxford St, Osu')->and($branch->phone)->toBe('024 555 0101');

    $owner = User::query()->withoutGlobalScopes()->where('email', 'efua@example.com')->sole();
    expect($owner->hasRole('owner'))->toBeTrue()->and($owner->branch_id)->toBeNull()
        ->and(auth()->id())->toBe($owner->id)
        ->and(app(PinAuthenticator::class)->attempt('1357', 'till:wizard', $tenant)->id)->toBe($owner->id);

    Tenancy::runAs($tenant, $branch, function () {
        $settings = app(SettingsService::class);
        expect(TaxComponent::query()->pluck('rate_basis_points', 'code')->all())->toEqual(['NHIL' => 250, 'GETFUND' => 250, 'VAT' => 1500])
            ->and(app(TaxService::class)->profileFor()->pricesIncludeTax)->toBeTrue()
            ->and($settings->get('receipt.tax_number'))->toBe('C0012345678')
            ->and($settings->get('receipt.header'))->toContain('12 Oxford St, Osu')
            ->and($settings->get('receipt.footer'))->toContain('delete them')
            ->and($settings->get('setup.completed_at'))->not->toBeNull()
            ->and(Till::query()->orderBy('code')->pluck('name', 'code')->all())->toBe(['T1' => 'Front counter', 'T2' => 'Bar'])
            ->and(Item::query()->pluck('name')->sort()->values()->all())->toBe(['Jollof Rice', 'Sobolo'])
            ->and(User::query()->where('name', 'Yaw Asante')->sole()->hasRole('waiter'))->toBeTrue();
    });

    // The wizard is closed now; the owner goes straight into the back office.
    $this->get('/setup')->assertRedirect(route('home'));
    $this->get('/admin')->assertOk();
});

it('keeps the owner on the screen with a mistake until it is fixed', function () {
    $wizard = Livewire::test(SetupWizard::class)->call('next')
        ->set('restaurant', 'Chop Bar')->set('tin', '12345')
        // Each screen is keyed, so the browser builds fresh boxes per screen (a reused box once kept typing
        // into the previous screen's field: owner details landed in the branch name and TIN).
        ->assertSeeHtml('wire:key="step-restaurant"')->assertSeeHtml('wire:key="field-tin"')
        ->call('next')->assertHasErrors(['tin'])->assertSet('step', 'restaurant')->assertSee('That is not a Ghana TIN')
        ->set('tin', 'GHA 123456789 0')->call('next')->assertHasNoErrors()->assertSet('step', 'owner');

    $wizard->set('ownerName', 'Efua')->set('email', 'not-an-email')->set('password', 'short')->set('password_confirmation', 'short')
        ->set('pin', '12')->set('pin_confirmation', '34')
        ->call('next')->assertHasErrors(['email', 'password', 'pin'])->assertSet('step', 'owner')
        ->set('email', 'efua@example.com')->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
        ->set('pin', '2580')->set('pin_confirmation', '2580')
        ->call('next')->assertHasNoErrors()->assertSet('step', 'taxes');

    // Tax: the owner must say whether menu prices include tax; rates must be sensible percentages.
    $wizard->set('rates.VAT', '150')->call('next')->assertHasErrors(['rates.VAT', 'pricesIncludeTax'])->assertSet('step', 'taxes')
        ->assertSee('Choose whether your menu prices include tax')
        ->set('rates.VAT', '15')->set('pricesIncludeTax', 'no')->call('next')->assertHasNoErrors()->assertSet('step', 'receipt');

    // Tills: no two with the same name.
    $wizard->call('next')->set('tills', ['Till 1', 'Till 1'])->call('next')->assertHasErrors(['tills.1'])->assertSet('step', 'tills')
        ->call('addTill')->assertCount('tills', 3)->call('removeTill')->call('removeTill')->assertCount('tills', 1)->call('removeTill')->assertCount('tills', 1)
        ->call('next')->assertSet('step', 'printers')->call('next')->assertSet('step', 'data')->call('next')->assertSet('step', 'backup');

    // Backup password: must be written down and its last 4 characters typed back.
    $wizard->call('next')->assertHasErrors(['backupWritten', 'backupCheck'])->assertSet('step', 'backup')
        ->set('backupWritten', true)->set('backupCheck', 'zzzz')->call('next')->assertHasErrors(['backupCheck'])
        ->assertSee('not the last 4 characters');

    expect(Tenant::query()->count())->toBe(0);
});

it('asks for the installer\'s setup code first when one is set, and slows down guessing', function () {
    config(['rms.setup.code' => '482913']);

    $wizard = Livewire::test(SetupWizard::class)->assertSee('Setup code')
        ->call('next')->assertSet('step', 'welcome'); // cannot skip the code

    foreach (range(1, 5) as $_) {
        $wizard->set('code', '000000')->call('unlock')->assertSee('That setup code is not right');
    }
    $wizard->set('code', '482913')->call('unlock')->assertSee('Too many wrong codes')->assertSet('step', 'welcome');

    $this->travel(61)->seconds();
    $wizard->set('code', '482 913')->call('unlock')->assertSet('unlocked', true)->assertSet('step', 'restaurant');
});

it('cannot be tricked into skipping screens or running twice', function () {
    expect(fn () => Livewire::test(SetupWizard::class)->set('step', 'review'))->toThrow(CannotUpdateLockedPropertyException::class)
        ->and(fn () => Livewire::test(SetupWizard::class)->set('unlocked', true))->toThrow(CannotUpdateLockedPropertyException::class);

    // Two people finishing at the same moment: the second is told the server is already set up.
    $first = walkSetup();
    $second = walkSetup(['email' => 'kofi@example.com']);
    $first->call('finish')->assertSet('step', 'done');
    $second->call('finish')->assertRedirect(route('home'));
    expect(Tenant::query()->count())->toBe(1);

    expect(fn () => app(SetupService::class)->complete(['restaurant' => 'X', 'branch' => 'Y', 'owner_name' => 'Z', 'email' => 'z@example.com', 'password' => 'secret-pass-1', 'pin' => '9999']))
        ->toThrow(UserFacingException::class, 'already set up');
});

it('still finishes setup when a spreadsheet has mistakes, and says what to fix', function () {
    $staff = setupCsv('staff', [
        ['', '024 100 0009', 'waiter', '2001', '', '', ''],              // no name
        ['Ama Owusu', '024 100 0004', 'cashier', '3003', 'Cashier', '', ''],
    ]);

    walkSetup(['staffFile' => $staff])->call('finish')->assertSet('step', 'done')
        ->assertSee('Staff: 1 new, 1 error')->assertSee('Settings → Import data');

    expect(Tenant::query()->count())->toBe(1)
        ->and(User::query()->withoutGlobalScopes()->where('name', 'Ama Owusu')->exists())->toBeTrue();
});
