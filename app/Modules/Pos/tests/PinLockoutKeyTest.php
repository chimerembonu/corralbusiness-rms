<?php

use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Re-review finding: the sign-in lockout was keyed on a public Livewire property, so a browser could send a
 * fresh till id with every attempt and never be locked out. The property is now locked.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create();
    $this->cashier->assignRole(Role::Cashier->value);
});

it('refuses a till id sent by the browser', function () {
    expect(fn () => Livewire::test(PinLogin::class)->set('tillId', 'made-up'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps counting wrong PINs on the same key however the login is retried', function () {
    $screen = Livewire::test(PinLogin::class)->call('chooseTill', $this->till->id);
    foreach (range(1, 5) as $i) {
        $screen->call('login', '0000');
    }
    expect($screen->get('lockedFor'))->toBeGreaterThan(0);

    // Choosing the same till again (the only server-side way to touch $tillId) does not reset the lockout.
    $screen->call('chooseTill', $this->till->id)->call('login', '1111');
    expect($screen->get('lockedFor'))->toBeGreaterThan(0)->and(auth()->check())->toBeFalse();
});
