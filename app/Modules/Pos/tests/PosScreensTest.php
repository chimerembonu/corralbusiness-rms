<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->table = DiningTable::create(['name' => 'T1', 'seats' => 4]);
    $this->owner = User::factory()->forBranch($this->branch)->withPin('1234')->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->waiter = User::factory()->forBranch($this->branch)->withPin('2222')->create(['name' => 'Ama']);
    $this->waiter->assignRole(Role::Waiter->value);
    // The tenant context stays set, as the SetTenantFromUser middleware would do on a real request.
});

it('sends guests to the PIN screen and signs staff in by PIN', function () {
    $this->get('/pos')->assertRedirect('/pos/login');
    $this->get('/pos/login')->assertOk()->assertSee('Enter your PIN');

    Livewire::test(PinLogin::class)->call('login', '9999')->assertSet('error', 'Wrong PIN. 4 attempts left.');
    Livewire::test(PinLogin::class)->call('login', '2222')->assertRedirect('/pos');

    $this->assertAuthenticatedAs($this->waiter);
    $this->get('/pos')->assertOk()->assertSee('Dine-in')->assertSee('Takeaway')->assertSee('Ama');
});

it('keeps staff without till access out', function () {
    $nobody = User::factory()->forBranch($this->branch)->withPin('3333')->create();

    $this->actingAs($nobody)->get('/pos')->assertRedirect('/pos/login');
    $this->assertGuest();
});

it('opens a takeaway order and a table order from the home screen', function () {
    $this->actingAs($this->waiter);

    Livewire::test(OrderTypeScreen::class)->call('startTakeaway')->assertRedirect();
    $takeaway = Order::withoutGlobalScopes()->latest('opened_at')->first();
    expect($takeaway->type->value)->toBe('takeaway')->and($takeaway->opened_by_id)->toBe($this->waiter->id);

    Livewire::test(OrderTypeScreen::class)->call('showTables')->assertSee('T1')->call('openTable', $this->table->id)->assertRedirect();
    $dineIn = Order::withoutGlobalScopes()->where('table_id', $this->table->id)->first();
    expect($dineIn)->not->toBeNull();

    // Tapping the occupied table again resumes the same order.
    Livewire::test(OrderTypeScreen::class)->call('openTable', $this->table->id)->assertRedirect(route('pos.order', $dineIn));
});

it('takes an order with the item picker, sends it and pays cash to a receipt', function () {
    $this->actingAs($this->waiter);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->waiter);

    $screen = Livewire::test(OrderScreen::class, ['order' => $order])
        ->assertSee('Drinks')->assertSee('Coca-Cola')                      // first tab (alphabetical)
        ->call('tapItem', $this->menu['coke']->id)                       // plain item → added straight away
        ->assertSee('GH₵ 12.00')                                          // 10.00 + 20 % tax
        ->call('selectCategory', $this->menu['rice']->id)
        ->assertSee('Jollof Rice')
        ->call('tapItem', $this->menu['jollof']->id)                     // has sizes + modifiers → picker opens
        ->assertSet('picker.item_id', $this->menu['jollof']->id)
        ->call('pickerAdd')                                               // protein is required
        ->assertSet('picker.errors.0', 'Choose a protein: choose at least 1.')
        ->call('pickerToggle', $this->menu['protein']->id, $this->menu['chicken']->id)
        ->call('pickerVariant', $this->menu['large']->id)
        ->call('pickerQuantity', 1)
        ->set('picker.notes', 'extra spicy')
        ->call('pickerAdd')
        ->assertSet('picker', null)
        ->assertSee('Jollof Rice (Large)')->assertSee('Chicken')->assertSee('extra spicy');

    $order = $order->fresh();
    expect($order->items)->toHaveCount(2)
        ->and($order->subtotal)->toBePesewas(1000 + 2 * 7500)           // coke 10 + 2 × (60 + 15)
        ->and($order->total)->toBePesewas(19200);                          // + 20 % tax

    $line = $order->items->firstWhere('name', 'Coca-Cola');
    $screen->call('changeLineQuantity', $line->id, 1)->call('send')->assertSet('notice', 'Sent to the kitchen.');
    expect($order->fresh()->status)->toBe(OrderStatus::Sent)
        ->and($order->fresh()->total)->toBePesewas(20400);

    $screen->call('pay')->assertRedirect(route('pos.pay', $order));

    Livewire::test(PaymentScreen::class, ['order' => $order])
        ->assertSee('GH₵ 204.00')
        ->call('setTendered', 25000)
        ->assertSet('tendered', '250.00')
        ->call('complete')
        ->assertRedirect(route('pos.receipt', [$order, 'print' => 1]));

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    $this->get(route('pos.receipt', $order))->assertOk()
        ->assertSee('80mm', false)->assertSee('NHIL 2.5%')->assertSee('VAT 15%')->assertSee('204.00')
        ->assertSee('Cash received')->assertSee('46.00')->assertSee('Ama')->assertSee('Test Chop Bar');
});

it('shows today\'s sales to a manager but not to a waiter', function () {
    $this->actingAs($this->owner)->get('/pos/today')->assertOk()->assertSee("Today's sales")->assertSee('Gross sales');
    $this->actingAs($this->waiter)->get('/pos/today')->assertForbidden();
});
