<?php

use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Test report 9 Nov 2026, H1: an owner or manager who signed in at a till with their 4-digit PIN could
 * open /admin and /hq with no password and no two-step code. The back office now refuses a PIN session.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->withPin('1234')->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Efua']);
    $this->manager->assignRole(Role::Manager->value);
});

it('does not open the back office with a till PIN', function (string $pin, string $url) {
    Livewire::test(PinLogin::class)->call('login', $pin)->assertRedirect('/pos');
    $this->assertAuthenticated();

    $panel = str_starts_with($url, '/hq') ? 'hq' : 'admin';
    $this->get($url)->assertRedirect("/{$panel}/login");
    $this->assertGuest(); // signed out: the next step is the password (and two-step code)

    expect(Activity::query()->where('log_name', 'security')->where('event', 'till_session_refused')->count())->toBe(1);
})->with([
    'manager → back office' => ['2468', '/admin'],
    'manager → staff list' => ['2468', '/admin/staff'],
    'owner → back office' => ['1234', '/admin'],
    'owner → head office' => ['1234', '/hq'],
]);

it('still opens the back office after a password sign-in, and the till after a PIN', function () {
    // A password sign-in carries no till mark.
    $this->actingAs($this->manager)->get('/admin')->assertOk();

    // Signing in at a till afterwards marks the session again.
    auth()->logout();
    Livewire::test(PinLogin::class)->call('login', '2468')->assertRedirect('/pos');
    $this->get('/pos')->assertOk();
    $this->get('/admin')->assertRedirect('/admin/login');
});
