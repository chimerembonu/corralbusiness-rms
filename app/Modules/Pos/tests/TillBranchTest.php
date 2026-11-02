<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

/*
 * Security review M7: a till user works in the TILL's branch, not their home branch, and staff from
 * another branch cannot sign in on it at all.
 */
beforeEach(function () {
    seedRoles();
    $this->branchA = provisionTenant();
    $this->branchB = Branch::create(['tenant_id' => $this->branchA->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);
    $this->tillA = Till::create(['name' => 'Till A', 'code' => 'TA']);
    $this->tillB = Till::create(['branch_id' => $this->branchB->id, 'name' => 'Till B', 'code' => 'TB']);
    $this->cashierA = User::factory()->forBranch($this->branchA)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashierA->assignRole(Role::Cashier->value);
    $this->cashierB = User::factory()->forBranch($this->branchB)->withPin('2222')->create(['name' => 'Yaw']);
    $this->cashierB->assignRole(Role::Cashier->value);
    // Like rms:setup, the owner is created with no home branch (the tenant context carries no branch here).
    $this->owner = Tenancy::runAs($this->branchA->tenant, null, fn () => User::factory()->withPin('9999')->create(['name' => 'Chimere', 'tenant_id' => $this->branchA->tenant_id]));
    $this->owner->assignRole(Role::Owner->value);
});

/** A request from a device that has chosen a till (the till cookie), with the tenant resolved by the middleware. */
function onTill(Till $till)
{
    Tenancy::forget();
    app()->forgetInstance(CurrentTill::class); // a new request reads the till cookie afresh
    test()->flushSession();

    return test()->withCookie(CurrentTill::COOKIE, $till->id);
}

it('refuses to sign in staff from another branch on this till', function () {
    app(CurrentTill::class)->remember($this->tillA);

    Livewire::test(PinLogin::class)->assertSet('tillId', $this->tillA->id)
        ->call('login', '2222')->assertSet('error', 'This till belongs to another branch. Sign in at your own branch, or ask a manager to move you.');
    $this->assertGuest();

    Livewire::test(PinLogin::class)->call('login', '1111')->assertRedirect('/pos');
    $this->assertAuthenticatedAs($this->cashierA);
});

it('sends an already signed-in user from another branch back to the sign-in screen', function () {
    onTill($this->tillA)->actingAs($this->cashierB)->get('/pos')->assertRedirect('/pos/login');
    $this->assertGuest();
});

it('books the owner\'s sales to the branch of the till they are on, not a home branch', function () {
    onTill($this->tillB)->actingAs($this->owner)->get('/pos')->assertOk();
    expect(Tenancy::branchId())->toBe($this->branchB->id);

    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->owner);
    expect($order->branch_id)->toBe($this->branchB->id);

    onTill($this->tillA)->actingAs($this->owner)->get('/pos')->assertOk();
    expect(Tenancy::branchId())->toBe($this->branchA->id);
});
