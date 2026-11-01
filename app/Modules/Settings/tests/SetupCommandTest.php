<?php

use App\Modules\Settings\Models\Tenant;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;

it('sets up a real restaurant with an owner login once, and reports whether one exists', function () {
    $this->artisan('rms:setup --check')->assertExitCode(1);

    $this->artisan('rms:setup', ['--restaurant' => 'Mama Efua Chop Bar', '--branch' => 'Osu', '--owner-name' => 'Efua Mensah', '--email' => 'efua@example.com', '--password' => 'secret-pass-1', '--pin' => '1357'])
        ->expectsOutputToContain('is ready')
        ->assertExitCode(0);

    $tenant = Tenant::query()->first();
    expect($tenant->name)->toBe('Mama Efua Chop Bar')->and($tenant->slug)->toBe('mama-efua-chop-bar')->and($tenant->branches()->count())->toBe(1);
    $owner = User::query()->withoutGlobalScopes()->where('email', 'efua@example.com')->first();
    expect($owner->hasRole('owner'))->toBeTrue()->and($owner->branch_id)->toBeNull()
        ->and(app(PinAuthenticator::class)->attempt('1357', 'till:test', $tenant)->id)->toBe($owner->id);

    $this->artisan('rms:setup --check')->assertExitCode(0);
    $this->artisan('rms:setup', ['--restaurant' => 'Another', '--branch' => 'B', '--owner-name' => 'X', '--email' => 'x@example.com', '--password' => 'secret-pass-1', '--pin' => '2468'])
        ->expectsOutputToContain('already set up')->assertExitCode(0);
    expect(Tenant::query()->count())->toBe(1);
});

it('refuses bad input', function () {
    $this->artisan('rms:setup', ['--restaurant' => 'R', '--branch' => 'B', '--owner-name' => 'X', '--email' => 'not-an-email', '--password' => 'short', '--pin' => '12'])->assertExitCode(1);
    $this->artisan('rms:setup', ['--restaurant' => 'R', '--branch' => 'B', '--owner-name' => 'X', '--email' => 'ok@example.com', '--password' => 'long-enough-1', '--pin' => '12'])->assertExitCode(1);
    expect(Tenant::query()->count())->toBe(0);
});
