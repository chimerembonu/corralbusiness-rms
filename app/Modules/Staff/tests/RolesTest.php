<?php

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role as RoleModel;

beforeEach(fn () => seedRoles());

it('creates the eight system roles', function () {
    expect(RoleModel::pluck('name')->sort()->values()->all())->toBe(collect(Role::values())->sort()->values()->all());
});

it('gives managers override powers that cashiers do not have', function () {
    $branch = provisionTenant();
    $manager = User::factory()->forBranch($branch)->create();
    $cashier = User::factory()->forBranch($branch)->create();
    $manager->assignRole(Role::Manager->value);
    $cashier->assignRole(Role::Cashier->value);

    expect($manager->can(Permission::OrdersVoid->value))->toBeTrue()
        ->and($manager->can(Permission::DayReopen->value))->toBeTrue()
        ->and($cashier->can(Permission::OrdersVoid->value))->toBeFalse()
        ->and($cashier->can(Permission::PaymentsTake->value))->toBeTrue()
        ->and($manager->can(Permission::HqAccess->value))->toBeFalse();
});

it('controls who may open the admin and HQ panels', function () {
    $branch = provisionTenant();
    $owner = User::factory()->forBranch($branch)->create();
    $waiter = User::factory()->forBranch($branch)->create();
    $owner->assignRole(Role::Owner->value);
    $waiter->assignRole(Role::Waiter->value);

    expect($owner->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($owner->canAccessPanel(Filament::getPanel('hq')))->toBeTrue()
        ->and($waiter->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('seeding twice is harmless', function () {
    seedRoles();
    expect(RoleModel::count())->toBe(8);
});
