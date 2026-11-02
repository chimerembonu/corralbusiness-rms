<?php

use App\Modules\Customers\Services\CustomerService;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Services\StockTakeService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Models\Till;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;

/*
 * Security review H4: a record reached by its ID in a URL is only shown to staff of the branch
 * (and restaurant) it belongs to. Everyone else gets "not found".
 */
beforeEach(function () {
    seedRoles();
    $this->branchA = provisionTenant('Chop Bar');
    $this->menu = demoMenu();
    $this->branchB = Branch::create(['tenant_id' => $this->branchA->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);
    $this->userA = User::factory()->forBranch($this->branchA)->withPin('1111')->create(['name' => 'Kofi']);
    $this->userA->assignRole(Role::Cashier->value);
    $this->userA->givePermissionTo([Permission::InventoryCount->value, Permission::CustomersManage->value]);
    $this->userB = User::factory()->forBranch($this->branchB)->withPin('2222')->create(['name' => 'Yaw']);
    $this->userB->assignRole(Role::Cashier->value);
    $this->userB->givePermissionTo([Permission::InventoryCount->value, Permission::CustomersManage->value]);

    // Everything below is created in branch A.
    $orders = app(OrderService::class);
    $this->order = $orders->open(OrderType::Takeaway, $this->userA);
    $orders->addItem($this->order, $this->menu['coke'], null, 1, [], null, $this->userA);
    $orders->sendToKitchen($this->order, $this->userA);
    Printer::create(['name' => 'Counter', 'driver' => 'browser', 'address' => 'counter', 'prints_receipts' => true]);
    $this->job = app(PrintService::class)->printReceipt($this->order)->first();
    $this->session = app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->userA, Money::fromDecimal('500'), now()->toDateString());
    Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'on_hand' => 10]);
    $this->take = app(StockTakeService::class)->start($this->userA);
    $this->customer = app(CustomerService::class)->create(['name' => 'Ama', 'phone' => '0241234567'], $this->userA);

    // A second restaurant with its own staff.
    $this->otherBranch = provisionTenant('Other Restaurant');
    $this->userC = User::factory()->forBranch($this->otherBranch)->withPin('3333')->create(['name' => 'Esi']);
    $this->userC->assignRole(Role::Cashier->value);
    $this->userC->givePermissionTo([Permission::InventoryCount->value, Permission::CustomersManage->value]);

    $this->urls = [
        'order' => '/pos/orders/'.$this->order->id,
        'pay' => '/pos/orders/'.$this->order->id.'/pay',
        'receipt' => '/pos/orders/'.$this->order->id.'/receipt',
        'print job' => '/print/jobs/'.$this->job->id,
        'cash session' => '/pos/cash/sessions/'.$this->session->id,
        'stock take' => '/inventory/stock-takes/'.$this->take->id.'/print',
    ];
});

/** Behave like a fresh browser request: the tenant is set by the middleware from the user, not left over from setup. */
function visitAs(User $user): void
{
    test()->flushSession();
    Tenancy::forget();
    test()->actingAs($user);
}

it('shows branch records to that branch\'s staff', function () {
    visitAs($this->userA);
    foreach ($this->urls as $what => $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/customers/'.$this->customer->id.'/statement')->assertOk();
    expect($this->job)->not->toBeNull();
});

it('hides branch A records from branch B staff who know the ID', function () {
    visitAs($this->userB);
    foreach ($this->urls as $what => $url) {
        $this->get($url)->assertNotFound();
    }
    $this->post('/print/jobs/'.$this->job->id.'/printed')->assertNotFound();
    expect($this->job->fresh()->printed_at)->toBeNull();

    // Customers are shared across a restaurant's branches, so the statement is still allowed.
    $this->get('/customers/'.$this->customer->id.'/statement')->assertOk();
});

it('hides every record from another restaurant\'s staff', function () {
    visitAs($this->userC);
    foreach ($this->urls as $what => $url) {
        $this->get($url)->assertNotFound();
    }
    $this->get('/customers/'.$this->customer->id.'/statement')->assertNotFound();
});
