<?php

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Resources\Staff\Pages\ListStaff;
use App\Modules\Staff\Filament\Resources\Staff\StaffResource;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Tenancy\Tenancy;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
 * Re-review: a manager could reset the owner's PIN (then approve voids and refunds as the owner) or
 * delete the owner. Owner accounts can now only be edited, deleted or re-PINned by an owner.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->withPin('1234')->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    Tenancy::forget();
});

it('keeps a manager away from the owner\'s PIN, edit form and delete button', function () {
    $this->actingAs($this->manager);
    $this->get(StaffResource::getUrl('edit', ['record' => $this->owner]))->assertForbidden();

    Livewire::test(ListStaff::class)
        ->assertActionHidden(TestAction::make('resetPin')->table($this->owner))
        ->assertActionHidden(TestAction::make('delete')->table($this->owner))
        ->assertActionVisible(TestAction::make('resetPin')->table($this->manager));

    Tenancy::set($this->branch->tenant, $this->branch);
    expect(app(PinAuthenticator::class)->attempt('1234', 'till:test')->id)->toBe($this->owner->id) // the owner's PIN is untouched
        ->and(User::query()->whereKey($this->owner->id)->exists())->toBeTrue();
});

it('still lets the owner manage their own account and the manager\'s', function () {
    $this->actingAs($this->owner);
    $this->get(StaffResource::getUrl('edit', ['record' => $this->owner]))->assertOk();
    Livewire::test(ListStaff::class)
        ->callAction(TestAction::make('resetPin')->table($this->manager), ['new_pin' => '9999'])->assertHasNoErrors();
    Tenancy::set($this->branch->tenant, $this->branch);
    expect(app(PinAuthenticator::class)->attempt('9999', 'till:test')->id)->toBe($this->manager->id);
});
