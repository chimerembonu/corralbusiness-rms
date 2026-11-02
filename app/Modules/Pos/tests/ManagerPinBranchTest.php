<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Livewire\Livewire;

/*
 * Security review L4: a manager override must come from a manager of THIS branch, and PIN lockouts
 * are counted per till, not per network address.
 */
beforeEach(function () {
    seedRoles();
    $this->branchA = provisionTenant();
    $this->menu = demoMenu();
    $this->branchB = Branch::create(['tenant_id' => $this->branchA->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);
    $this->cashier = User::factory()->forBranch($this->branchA)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->managerA = User::factory()->forBranch($this->branchA)->withPin('2468')->create(['name' => 'Akosua']);
    $this->managerA->assignRole(Role::Manager->value);
    $this->managerB = User::factory()->forBranch($this->branchB)->withPin('3579')->create(['name' => 'Yaw']);
    $this->managerB->assignRole(Role::Manager->value);
    $this->owner = User::factory()->withPin('9999')->create(['name' => 'Chimere', 'tenant_id' => $this->branchA->tenant_id, 'branch_id' => null]); // the owner has no home branch
    $this->owner->assignRole(Role::Owner->value);
    $this->tillA1 = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $this->tillA2 = Till::create(['name' => 'Till 2', 'code' => 'T2']);

    $orders = app(OrderService::class);
    $this->order = $orders->open(OrderType::Takeaway, $this->cashier);
    $this->line = $orders->addItem($this->order, $this->menu['coke'], null, 1, [], null, $this->cashier);
    $orders->sendToKitchen($this->order, $this->cashier);
});

it('refuses a manager PIN from another branch and accepts this branch\'s manager or the owner', function () {
    $this->actingAs($this->cashier);
    $screen = Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('startVoid', $this->line->id)->call('chooseVoidReason', 'Kitchen mistake')->call('confirmVoid')
        ->call('approve', '3579')->assertSet('approval.error', 'That manager works at another branch and cannot approve this here.');
    expect($this->line->fresh()->voided_at)->toBeNull();

    $screen->call('approve', '9999')->assertSet('approval', null);
    expect($this->line->fresh()->voided_by_id)->toBe($this->owner->id);
});

it('locks one till after five wrong PINs without locking the next till on the same network', function () {
    app(CurrentTill::class)->remember($this->tillA1);
    $login = Livewire::test(PinLogin::class)->assertSet('tillId', $this->tillA1->id);
    foreach (range(1, 5) as $i) {
        $login->call('login', '0000');
    }
    $login->assertSet('lockedFor', 60)->call('login', '1111')->assertSet('lockedFor', 60);
    $this->assertGuest();

    app(CurrentTill::class)->remember($this->tillA2);
    Livewire::test(PinLogin::class)->assertSet('tillId', $this->tillA2->id)->call('login', '1111')->assertRedirect('/pos');
    $this->assertAuthenticatedAs($this->cashier);
});
