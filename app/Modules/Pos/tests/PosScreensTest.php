<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;
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

    Livewire::test(OrderTypeScreen::class)->call('showTables')->assertSee('T1')
        ->call('tapTable', $this->table->id)->assertSet('coversFor', $this->table->id)->assertSet('covers', 4)->assertSee('how many guests')
        ->call('setCovers', 3)->call('adjustCovers', -1)
        ->call('openTable')->assertRedirect();
    $dineIn = Order::withoutGlobalScopes()->where('table_id', $this->table->id)->first();
    expect($dineIn)->not->toBeNull()->and($dineIn->covers)->toBe(2);

    // Tapping the occupied table again resumes the same order.
    Livewire::test(OrderTypeScreen::class)->call('tapTable', $this->table->id)->assertRedirect(route('pos.order', $dineIn));
});

it('shows the floor plan with sections and colours tables by status', function () {
    $this->actingAs($this->waiter);
    $terrace = FloorSection::create(['name' => 'Terrace', 'sort_order' => 2]);
    $indoor = FloorSection::create(['name' => 'Indoor', 'sort_order' => 1]);
    $this->table->update(['section_id' => $indoor->id, 'pos_x' => 2, 'pos_y' => 1]);
    $p1 = DiningTable::create(['name' => 'P1', 'seats' => 6, 'section_id' => $terrace->id]);
    $order = app(OrderService::class)->open(OrderType::DineIn, $this->waiter, $this->table, 3);
    app(OrderService::class)->addItem($order, $this->menu['coke']);
    app(OrderService::class)->sendToKitchen($order, $this->waiter);

    Livewire::test(OrderTypeScreen::class)->call('showTables')
        ->assertSeeInOrder(['Indoor', 'Terrace'])
        ->assertSee('T1')->assertSee('data-status="ordered"', false)->assertSee('3 guests')->assertDontSee('P1')
        ->assertSee('left: 10%', false)                      // pos_x 2 of 20 columns
        ->call('selectSection', $terrace->id)
        ->assertSee('P1')->assertSee('data-status="free"', false)->assertSee('6 seats')->assertDontSee('T1');
});

it('moves, joins and changes guests from the order screen table sheet', function () {
    $this->actingAs($this->waiter);
    $t2 = DiningTable::create(['name' => 'T2', 'seats' => 4]);
    $t3 = DiningTable::create(['name' => 'T3', 'seats' => 2]);
    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::DineIn, $this->waiter, $this->table, 2);
    $orders->addItem($order, $this->menu['coke']);
    $other = $orders->open(OrderType::DineIn, $this->waiter, $t3, 1);
    $orders->addItem($other, $this->menu['coke']);

    $screen = Livewire::test(OrderScreen::class, ['order' => $order])
        ->assertSee('Table T1')->assertSee('2 guests')
        ->call('openTableSheet')->assertSee('Move to another table')->assertSee('Join another table')
        ->call('openTableSheet', 'covers')->call('adjustCovers', 2)->call('saveCovers')
        ->assertSet('tableSheet', null)->assertSee('4 guests')
        ->call('openTableSheet', 'move')->assertSee('Tap a free table')
        ->call('moveTo', $t3->id)->assertSee('already has an open order')   // occupied → refused
        ->call('moveTo', $t2->id)->assertSet('tableSheet', null)->assertSee('Order moved to table T2')->assertSee('Table T2')
        ->call('openTableSheet', 'merge')
        ->call('mergeWith', $t3->id)->assertSee('Table T3 joined')->assertSee('Joined: T3');

    expect($order->fresh()->table_id)->toBe($t2->id)
        ->and($order->fresh()->covers)->toBe(5)
        ->and($order->fresh()->items()->count())->toBe(2)
        ->and($other->fresh()->status)->toBe(OrderStatus::Merged)
        ->and($t3->fresh()->merged_into_id)->toBe($t2->id);

    // Tapping the joined table on the home screen opens the main order.
    Livewire::test(OrderTypeScreen::class)->call('showTables')->call('tapTable', $t3->id)->assertRedirect(route('pos.order', $order));
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

it('lists today\'s orders with their table names', function () {
    $this->actingAs($this->waiter);
    $service = app(OrderService::class);
    $dineIn = $service->open(OrderType::DineIn, $this->waiter, $this->table);
    $service->addItem($dineIn, $this->menu['coke']);
    $takeaway = $service->open(OrderType::Takeaway, $this->waiter);
    $service->addItem($takeaway, $this->menu['coke']);

    $this->actingAs($this->owner)->get('/pos/today')->assertOk()
        ->assertSee('#'.$dineIn->displayNumber())->assertSee('T1')
        ->assertSee('#'.$takeaway->displayNumber())->assertSee('Takeaway');
});
