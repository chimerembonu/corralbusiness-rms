<?php

use App\Modules\Settings\Filament\Pages\SecurityLogPage;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Filament\Auth\Login;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Audit\Activity;
use App\Support\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Compliance assessment (4 Nov 2026): failed sign-ins and PIN attempts are recorded, never with the
 * PIN or password, and shown to the owner and managers on Back office → Settings → Security log.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->withPin('9999')->create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Correct-Horse-1']);
    $this->owner->assignRole(Role::Owner->value);
    $this->waiter = User::factory()->forBranch($this->branch)->withPin('5151')->create(['name' => 'Ama']);
    $this->waiter->assignRole(Role::Waiter->value);
    $this->pins = app(PinAuthenticator::class);
});

function securityEvents(): array
{
    return Activity::query()->where('log_name', 'security')->orderBy('id')->pluck('event')->all();
}

it('records wrong PINs and the lock-out, without the PIN', function () {
    foreach (range(1, 5) as $try) {
        try {
            $this->pins->attempt('1230', 'till:T1');
        } catch (InvalidPinException|PinLockedException) {
        }
    }

    expect(securityEvents())->toBe(['pin_failed', 'pin_failed', 'pin_failed', 'pin_failed', 'pin_failed', 'pin_locked_out']);
    $row = Activity::query()->where('event', 'pin_failed')->first();
    expect($row->properties['till'])->toBe('till:T1')->and($row->tenant_id)->toBe($this->branch->tenant_id)
        // Only what was written about each attempt: a time stamp can contain "1230" by chance (e.g. 12:30:01.123045).
        ->and(json_encode(Activity::query()->get(['description', 'event', 'properties', 'attribute_changes'])->toArray()))->not->toContain('1230');
});

it('flags a switched-off staff member trying their old PIN', function () {
    $this->waiter->forceFill(['is_active' => false])->save();
    expect(fn () => $this->pins->attempt('5151', 'till:T1'))->toThrow(InvalidPinException::class);

    $row = Activity::query()->where('log_name', 'security')->sole();
    expect($row->event)->toBe('pin_inactive_user')->and($row->causer_id)->toBe($this->waiter->id);
});

it('records a PIN that may not approve, e.g. a waiter on the manager keypad', function () {
    expect(fn () => $this->pins->authorize('5151', Permission::OrdersVoid, 'till:T1'))->toThrow(ManagerPinRequiredException::class);

    $row = Activity::query()->where('log_name', 'security')->sole();
    expect($row->event)->toBe('approval_refused')->and($row->properties['needs'])->toBe('orders.void')->and($row->causer_id)->toBe($this->waiter->id);
});

it('records a wrong back-office password with the email typed, never the password', function () {
    Tenancy::forget();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(Login::class)->fillForm(['email' => 'owner@example.com', 'password' => 'Wrong-Guess-77'])->call('authenticate')->assertHasFormErrors();
    Livewire::test(Login::class)->fillForm(['email' => 'nobody@example.com', 'password' => 'Wrong-Guess-88'])->call('authenticate');

    $rows = Activity::query()->where('log_name', 'security')->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['login_failed', 'login_failed'])
        ->and($rows[0]->properties['email'])->toBe('owner@example.com')->and($rows[0]->causer_id)->toBe($this->owner->id)
        ->and($rows[1]->properties['email'])->toBe('nobody@example.com')->and($rows[1]->causer_id)->toBeNull()
        ->and($rows->every(fn ($r) => $r->properties['panel'] === 'admin'))->toBeTrue()
        ->and(json_encode($rows->toArray()))->not->toContain('Wrong-Guess');
});

it('shows the log to the owner and managers only', function () {
    try {
        $this->pins->attempt('1230', 'till:T1');
    } catch (InvalidPinException) {
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->actingAs($this->owner);
    Livewire::test(SecurityLogPage::class)->assertOk()->assertSee('Wrong PIN')->assertSee('T1');

    $accountant = User::factory()->forBranch($this->branch)->create();
    $accountant->assignRole(Role::Accountant->value);
    $this->actingAs($accountant)->get(SecurityLogPage::getUrl())->assertForbidden();
});

it('records when the back-office sign-in is blocked after too many tries', function () {
    Tenancy::forget();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    foreach (range(1, 6) as $try) {
        Livewire::test(Login::class)->fillForm(['email' => 'owner@example.com', 'password' => 'Wrong-'.$try])->call('authenticate');
    }

    $throttled = Activity::query()->where('log_name', 'security')->where('event', 'login_throttled')->sole();
    expect($throttled->properties['email'])->toBe('owner@example.com')->and($throttled->tenant_id)->not->toBeNull();
});
