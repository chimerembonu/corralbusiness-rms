<?php

use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Auth\Login;
use App\Modules\Staff\Models\User;
use App\Support\Features\FeatureService;
use App\Support\Filament\RequireTwoStepSignIn;
use App\Support\Filament\SignOutIdleBackOffice;
use App\Support\Http\UserActivity;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * Complete Edition E9.2 — safer sign-in: tills lock themselves when nobody touches them (security review
 * D1), the back office signs out when unused, and owners and managers can be made to use two-step sign-in.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua', 'email' => 'akosua@example.com', 'password' => 'secret-pass-1']);
    $this->manager->assignRole(Role::Manager->value);
    $this->owner = Tenancy::runAs($this->branch->tenant, null, function () {
        $owner = User::factory()->create(['tenant_id' => $this->branch->tenant_id, 'branch_id' => null, 'name' => 'Efua', 'email' => 'efua@example.com', 'password' => 'secret-pass-1']);
        $owner->assignRole(Role::Owner->value);

        return $owner;
    });
    installTestLicence('business');
});

it('tells a person from a screen refreshing itself', function () {
    $livewire = fn (array $components) => tap(Request::create('/livewire/update', 'POST', ['components' => $components]), fn ($r) => $r->headers->set('X-Livewire', 'true'));

    expect(UserActivity::isPerson(Request::create('/pos')))->toBeTrue()
        ->and(UserActivity::isPerson($livewire([['calls' => [['method' => '$refresh']], 'updates' => []]])))->toBeFalse()     // wire:poll
        ->and(UserActivity::isPerson($livewire([['calls' => [['method' => 'addItem']], 'updates' => []]])))->toBeTrue()       // a button
        ->and(UserActivity::isPerson($livewire([['calls' => [], 'updates' => ['search' => 'kof']]])))->toBeTrue();            // typing
});

it('locks a till nobody has touched for the set minutes, and only then (security review D1)', function () {
    expect(app(TillSettings::class)->tillLockMinutes())->toBe(5); // the default

    $this->actingAs($this->cashier);
    $this->get('/pos')->assertOk()
        ->assertSee('id="idle-lock"', false)->assertSee('5 * 60 * 1000', false); // the screen's own timer

    $this->travel(4)->minutes();
    $this->get('/pos')->assertOk(); // still in use: each visit resets the timer

    $this->travel(6)->minutes();
    $this->get('/pos')->assertRedirect(route('pos.login'))->assertSessionHas('status', 'The till locked itself after 5 minutes without use. Enter your PIN to carry on.');
    $this->assertGuest();

    // The screen's timer signs out the same way, and says why.
    $this->actingAs($this->cashier);
    $this->post(route('pos.logout'), ['idle' => 1])->assertRedirect(route('pos.login'))->assertSessionHas('status');

    // 0 = never lock.
    app(SettingsService::class)->set(TillSettings::TILL_LOCK_MINUTES, 0);
    $this->actingAs($this->cashier);
    $this->get('/pos')->assertOk()->assertDontSee('id="idle-lock"', false);
    $this->travel(3)->hours();
    $this->get('/pos')->assertOk();
});

it('does not lock the kitchen screen, which shares the till layout', function () {
    $cook = User::factory()->forBranch($this->branch)->create();
    $cook->assignRole(Role::Kitchen->value);
    $this->actingAs($cook)->get(route('kitchen.display'))->assertOk()->assertDontSee('id="idle-lock"', false);
});

it('signs the back office out after the set minutes unused, and keeps it signed in while used', function () {
    expect(app(TillSettings::class)->backOfficeIdleMinutes())->toBe(30); // the default

    $this->actingAs($this->manager);
    $this->get('/admin')->assertOk();
    $this->travel(20)->minutes();
    $this->get('/admin')->assertOk();
    $this->travel(20)->minutes();
    $this->get('/admin')->assertOk(); // 20 minutes since the last use: fine

    $this->travel(31)->minutes();
    $this->get('/admin')->assertRedirect(filament()->getPanel('admin')->getLoginUrl());
    $this->assertGuest();
    expect(session(SignOutIdleBackOffice::LAST_ACTIVITY))->toBeNull();
});

it('makes owners and managers set up two-step sign-in once it is switched on, and nobody else', function () {
    $this->actingAs($this->owner)->get('/admin')->assertOk(); // switched off by default
    app(FeatureService::class)->set('two_step_sign_in', true, $this->owner);

    // Not set up yet: everything sends them to their profile page, where it is set up.
    $this->actingAs($this->owner)->get('/admin')->assertRedirect(filament()->getPanel('admin')->getProfileUrl());
    $this->get('/admin/items')->assertRedirect(filament()->getPanel('admin')->getProfileUrl());
    $this->get(filament()->getPanel('admin')->getProfileUrl())->assertOk()->assertSee('Authenticator app');
    $this->flushSession(); // a new browser session for the manager
    $this->actingAs($this->manager)->get('/admin')->assertRedirect(filament()->getPanel('admin')->getProfileUrl());

    $accountant = User::factory()->forBranch($this->branch)->create();
    $accountant->assignRole(Role::Accountant->value);
    expect(RequireTwoStepSignIn::required($accountant))->toBeFalse()
        ->and(RequireTwoStepSignIn::required($this->owner))->toBeTrue();

    // Set up: in.
    $this->owner->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
    $this->flushSession();
    $this->actingAs($this->owner->fresh())->get('/admin')->assertOk();
});

it('asks for the authenticator code at sign-in once it is set up, and the password alone is not enough', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    $this->owner->saveAppAuthenticationSecret($secret);
    $this->owner->saveAppAuthenticationRecoveryCodes(['recovery-code-one']);

    filament()->setCurrentPanel(filament()->getPanel('admin'));
    $login = Livewire::test(Login::class)
        ->fillForm(['email' => 'efua@example.com', 'password' => 'secret-pass-1'])->call('authenticate');
    $this->assertGuest(); // the password was right, but a code is needed

    $login->set('data.multiFactor.app.code', '000000')->call('authenticate');
    $this->assertGuest(); // a wrong code

    $login->set('data.multiFactor.app.code', $google2fa->getCurrentOtp($secret))->call('authenticate')->assertHasNoErrors();
    $this->assertAuthenticatedAs($this->owner);
});

it('lets the server reset two-step sign-in for someone who lost their phone and codes', function () {
    $this->owner->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());

    $this->artisan('rms:two-step:reset', ['email' => 'EFUA@example.com'])->expectsOutputToContain('switched off for Efua')->assertSuccessful();
    expect($this->owner->fresh()->hasTwoStepSignIn())->toBeFalse()
        ->and($this->owner->fresh()->getAppAuthenticationRecoveryCodes())->toBeNull();
    $this->artisan('rms:two-step:reset', ['email' => 'nobody@example.com'])->assertFailed();

    // Stored locked: the secret is never readable in the database.
    $this->owner->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    expect(DB::table('users')->where('id', $this->owner->id)->value('app_authentication_secret'))->not->toContain('JBSWY3DPEHPK3PXP');
});
