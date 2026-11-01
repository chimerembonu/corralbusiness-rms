<?php

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Livewire\ClockScreen;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\RosterService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->ama = User::factory()->forBranch($this->branch)->withPin('2222')->create(['name' => 'Ama']);
    $this->ama->assignRole(Role::Waiter->value);
});

it('clocks staff in and out from the PIN clock screen without logging in', function () {
    Carbon::setTestNow('2026-11-02 08:55:00');
    app(RosterService::class)->addShift($this->ama, '2026-11-02', '09:00', '17:00');

    $this->get('/clock')->assertOk()->assertSee('Clock in / out')->assertSee('Nobody is clocked in');
    $this->assertGuest();

    $screen = Livewire::test(ClockScreen::class)
        ->call('punch', '9999')->assertSet('error', 'Wrong PIN. 4 attempts left.')
        ->call('punch', '2222')
        ->assertSet('message', 'Welcome, Ama! Clocked in at 08:55.')
        ->assertSet('detail', 'Today you are rostered 09:00–17:00.')
        ->assertSee('Ama')->assertSee('since 08:55');
    $this->assertGuest();

    Carbon::setTestNow('2026-11-02 17:10:00');
    $screen->call('punch', '2222')->assertSet('message', 'Goodbye, Ama. Clocked out at 17:10 · worked 8h 15m.')->assertSee('Nobody is clocked in');
    Carbon::setTestNow();
});

it('has a clock button in the till top bar', function () {
    $this->actingAs($this->ama);
    $this->get('/pos')->assertOk()->assertSee('Clock in')->assertDontSee('Clock out');

    $this->post('/pos/clock')->assertRedirect()->assertSessionHas('status');
    expect($this->ama->openTimeEntry())->not->toBeNull()->and($this->ama->openTimeEntry()->source->value)->toBe('pos');
    $this->get('/pos')->assertSee('Clock out');

    $this->post('/pos/clock')->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'You are clocked out'));
    expect($this->ama->openTimeEntry())->toBeNull();
});
