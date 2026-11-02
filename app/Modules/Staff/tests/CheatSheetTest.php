<?php

use App\Modules\Kitchen\Models\Station;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;

it('builds the staff cheat-sheet from the restaurant\'s own setup, as a page and a PDF', function () {
    seedRoles();
    $branch = provisionTenant('Mama Efua Chop Bar');
    Till::create(['name' => 'Till 1 – Counter', 'code' => 'T1']);
    Station::create(['name' => 'Grill', 'code' => 'GRILL']);
    $manager = User::factory()->forBranch($branch)->create();
    $manager->assignRole(Role::Manager->value);

    $this->actingAs($manager)->get('/staff/cheat-sheet')->assertOk()
        ->assertSee('Mama Efua Chop Bar')->assertSee('Till 1 – Counter')->assertSee('Grill')->assertSee('/pos')->assertSee('Close day (Z report)')->assertSee('Do not cook it');
    $this->actingAs($manager)->get('/staff/cheat-sheet?pdf=1')->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload('corralbusiness-staff-cheat-sheet.pdf');
    $this->actingAs($manager)->get('/admin/staff')->assertOk()->assertSee('Cheat-sheet');

    $waiter = User::factory()->forBranch($branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->flushSession();
    $this->actingAs($waiter)->get('/staff/cheat-sheet')->assertForbidden();
});

it('tells staff to lock the till when they leave it and to take account payments at a till', function () {
    seedRoles();
    $branch = provisionTenant();
    $manager = User::factory()->forBranch($branch)->create();
    $manager->assignRole(Role::Manager->value);

    $this->actingAs($manager)->get('/staff/cheat-sheet')->assertOk()
        ->assertSee('Leaving the till')->assertSee('Tap Lock at the top of the till')
        ->assertSee('Customer paying off their account')->assertSee('Never in the back office')
        ->assertSee('Customer account payments')->assertSee('Do not receive them in the back office');

    // The words match the buttons on the screens they point at.
    expect(__('pos::pos.nav.lock'))->toBe('Lock')
        ->and(__('customers::customers.pos.nav'))->toBe('Customers')
        ->and(__('customers::customers.account.receive'))->toBe('Receive payment');
});
