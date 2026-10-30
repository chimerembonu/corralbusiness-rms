<?php

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Tenancy\Tenancy;

beforeEach(function () {
    $this->branch = provisionTenant();
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1234')->create(['name' => 'Kofi']);
    $this->auth = app(PinAuthenticator::class);
});

it('logs a staff member in with the right PIN', function () {
    $user = $this->auth->attempt('1234', 'till-1');

    expect($user->id)->toBe($this->cashier->id)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('stores the PIN hashed, never in clear text', function () {
    expect($this->cashier->pin)->not->toBe('1234')
        ->and($this->cashier->verifyPin('1234'))->toBeTrue()
        ->and($this->cashier->verifyPin('4321'))->toBeFalse()
        ->and($this->cashier->toArray())->not->toHaveKeys(['pin', 'pin_lookup']);
});

it('rejects a wrong PIN and counts attempts left', function () {
    try {
        $this->auth->attempt('0000', 'till-1');
        $this->fail('Expected InvalidPinException');
    } catch (InvalidPinException $e) {
        expect($e->attemptsLeft)->toBe(4);
    }
});

it('locks the till for 60 seconds after 5 wrong PINs', function () {
    foreach (range(1, 4) as $i) {
        expect(fn () => $this->auth->attempt('0000', 'till-1'))->toThrow(InvalidPinException::class);
    }

    expect(fn () => $this->auth->attempt('0000', 'till-1'))->toThrow(PinLockedException::class);

    // Even the RIGHT pin is refused while locked.
    expect(fn () => $this->auth->attempt('1234', 'till-1'))->toThrow(PinLockedException::class)
        ->and($this->auth->isLocked('till-1'))->toBeTrue()
        ->and($this->auth->secondsUntilUnlocked('till-1'))->toBeLessThanOrEqual(60);

    // Another till is not affected.
    expect($this->auth->attempt('1234', 'till-2')->id)->toBe($this->cashier->id);

    $this->travel(61)->seconds();

    expect($this->auth->attempt('1234', 'till-1')->id)->toBe($this->cashier->id)
        ->and($this->auth->isLocked('till-1'))->toBeFalse();
});

it('resets the counter after a successful login', function () {
    expect(fn () => $this->auth->attempt('0000', 'till-1'))->toThrow(InvalidPinException::class);
    $this->auth->attempt('1234', 'till-1');

    try {
        $this->auth->attempt('0000', 'till-1');
    } catch (InvalidPinException $e) {
        expect($e->attemptsLeft)->toBe(4);
    }
});

it('refuses inactive staff', function () {
    $this->cashier->update(['is_active' => false]);

    expect(fn () => $this->auth->attempt('1234', 'till-1'))->toThrow(InvalidPinException::class);
});

it('keeps PINs separate per restaurant', function () {
    $other = provisionTenant('Other Place');
    User::factory()->forBranch($other)->withPin('1234')->create(['name' => 'Yaw']);

    expect($this->auth->attempt('1234', 'till-1', $other->tenant)->name)->toBe('Yaw')
        ->and($this->auth->attempt('1234', 'till-1', $this->branch->tenant)->name)->toBe('Kofi');

    Tenancy::set($this->branch->tenant, $this->branch);
});

it('rejects PINs that are not 4 to 6 digits', function (string $pin) {
    User::factory()->forBranch($this->branch)->withPin($pin)->create();
})->with(['123', '1234567', 'abcd', '12 34'])->throws(InvalidArgumentException::class);

it('requires a manager PIN for voids', function () {
    seedRoles();
    $manager = User::factory()->forBranch($this->branch)->withPin('9999')->create();
    $manager->assignRole(Role::Manager->value);
    $this->cashier->assignRole(Role::Cashier->value);

    expect($this->auth->authorize('9999', Permission::OrdersVoid, 'till-1')->id)->toBe($manager->id);

    expect(fn () => $this->auth->authorize('1234', Permission::OrdersVoid, 'till-1'))
        ->toThrow(ManagerPinRequiredException::class);
});
