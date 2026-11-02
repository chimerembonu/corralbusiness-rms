<?php

use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Security review H1: switching a till into training mode is checked on the server, not only by the keypad.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->actingAs($this->cashier);
});

it('refuses to switch training mode when the approver lacks the permission, even if the step is reached', function () {
    expect($this->cashier->can(Permission::TrainingMode->value))->toBeFalse();

    $screen = Livewire::test(OrderTypeScreen::class)->instance();
    $step = new ReflectionMethod($screen, 'trainingApproved');

    expect(fn () => $step->invoke($screen, $this->cashier, true))->toThrow(HttpException::class);

    expect(app(TrainingMode::class)->isOn())->toBeFalse()
        ->and(Activity::query()->where('log_name', 'training_mode')->exists())->toBeFalse();
});

it('switches training mode and logs it when a real manager approves by PIN', function () {
    Livewire::test(OrderTypeScreen::class)->call('toggleTraining')
        ->assertSet('approval.permission', Permission::TrainingMode->value)
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')->assertRedirect(route('pos.home'));

    $log = Activity::query()->where('log_name', 'training_mode')->sole();
    expect($log->description)->toBe('training_on')
        ->and($log->properties['approved_by'])->toBe($this->manager->id)
        ->and($log->causer_id)->toBe($this->cashier->id);
});
