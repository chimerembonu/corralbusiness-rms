<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\DeliveriesBoard;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrdersList;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Livewire\RiderScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
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
    // A cashier with an open till (float counted), as every payment must land in a cashier session (Phase 1.6).
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    app(CashService::class)->open($this->till, $this->cashier, Money::fromDecimal('500'), now()->toDateString());
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

    $screen->call('pay')->assertSet('error', 'Ask a cashier to take the payment.'); // waiters hand over to a cashier
    $this->actingAs($this->cashier);
    Livewire::test(OrderScreen::class, ['order' => $order])->call('pay')->assertRedirect(route('pos.pay', $order));

    Livewire::test(PaymentScreen::class, ['order' => $order])
        ->assertSee('GH₵ 204.00')
        ->call('setAmount', 25000)
        ->assertSet('amount', '250.00')
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

it('applies discounts and promo codes from the order screen, asking a manager above the threshold', function () {
    $this->actingAs($this->waiter);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    PromoCode::create(['code' => 'WELCOME10', 'name' => 'Welcome', 'type' => 'percent', 'value' => 1000]);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->waiter);
    app(OrderService::class)->addItem($order, $this->menu['coke'], quantity: 5); // 50.00 + tax 10.00

    $screen = Livewire::test(OrderScreen::class, ['order' => $order])
        ->call('openModal', 'actions')->assertSee('Promo code')->assertSee('Order note')
        ->call('startDiscount')->assertSet('modal', 'discount')
        ->call('setDiscountValue', '5')->call('chooseDiscountReason', 'Loyal customer')->call('applyDiscount')
        ->assertSet('modal', null)->assertSee('− GH₵ 2.50')
        // 25 % is above the 10 % threshold → the manager keypad opens
        ->call('startDiscount')->call('setDiscountValue', '25')->call('chooseDiscountReason', 'Complaint')->call('applyDiscount')
        ->assertSet('approval.permission', 'orders.discount')->assertSee('Manager approval')
        ->call('approve', '9999')->assertSet('approval.error', 'Wrong PIN. 4 attempts left.')
        ->call('approve', '2468')->assertSet('approval', null)->assertSee('− GH₵ 12.50');

    expect($order->fresh()->discount_approved_by_id)->toBe($this->manager->id);

    $screen->call('openModal', 'promo')->set('promoCode', 'nope')->call('applyPromoCode')->assertSee('Unknown promo code')
        ->set('promoCode', 'welcome10')->call('applyPromoCode')->assertSet('modal', null)->assertSee('WELCOME10 (10%)');
    expect($order->fresh()->promo_code)->toBe('WELCOME10');
});

it('voids a sent line with a reason and a manager PIN, and fires a held course', function () {
    $this->actingAs($this->waiter);
    $manager = User::factory()->forBranch($this->branch)->withPin('2468')->create();
    $manager->assignRole(Role::Manager->value);
    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::DineIn, $this->waiter, $this->table, 2);
    $coke = $orders->addItem($order, $this->menu['coke']);
    $water = $orders->addItem($order, $this->menu['water']);

    Livewire::test(OrderScreen::class, ['order' => $order])
        ->call('cycleCourse', $coke->id)->assertSee('Course 2')
        ->call('cycleSeat', $water->id)->assertSee('Seat 1')
        ->call('send')->assertSee('Fire course 2')
        ->call('startVoid', $water->id)->assertSee('Void Bottled Water')
        ->call('confirmVoid')->assertSee('Please choose a reason')
        ->call('chooseVoidReason', 'Kitchen mistake')->call('confirmVoid')->assertSet('approval.method', 'voidApproved')
        ->call('approve', '2468')->assertSee('Voided: Kitchen mistake')
        ->call('fireCourse', 2)->assertSee('Course 2 sent to the kitchen')->assertDontSee('Fire course 2');

    expect($water->fresh()->isVoided())->toBeTrue()->and($coke->fresh()->fired_at)->not->toBeNull();
});

it('takes Mobile Money with a reference, then the rest in cash with a tip', function () {
    $this->actingAs($this->cashier);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->cashier);
    app(OrderService::class)->addItem($order, $this->menu['coke'], quantity: 5); // 60.00

    Livewire::test(PaymentScreen::class, ['order' => $order])
        ->call('setMethod', 'momo')->assertSet('amount', '60.00')->assertSee('MTN MoMo')
        ->set('amount', '40')->call('complete')->assertSee('Choose the Mobile Money network')
        ->call('setProvider', 'mtn')->call('complete')->assertSee('Enter the transaction reference')
        ->set('reference', 'MP1234')->call('complete')->assertSee('Mobile Money (MTN MoMo)')->assertSee('GH₵ 20.00')
        ->call('setMethod', 'cash')->call('setAmount', 5000)->call('setTip', 500)->call('complete')
        ->assertRedirect(route('pos.receipt', [$order, 'print' => 1]));

    $order = $order->fresh();
    expect($order->isPaid())->toBeTrue()->and($order->tip_total)->toBePesewas(500)->and($order->payments()->count())->toBe(2);
    $this->get(route('pos.receipt', $order))->assertOk()->assertSee('Mobile Money (MTN MoMo)')->assertSee('MP1234')->assertSee('Tip');
});

it('finds an order, reprints its receipt and refunds a payment with a manager PIN', function () {
    $this->actingAs($this->owner);
    $manager = User::factory()->forBranch($this->branch)->withPin('2468')->create();
    $manager->assignRole(Role::Manager->value);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->owner);
    app(OrderService::class)->addItem($order, $this->menu['coke']);
    app(OrderService::class)->hold($order, 'Kofi', $this->owner);
    $this->get('/pos')->assertSee('Kofi')->assertSee('On hold');
    $payment = app(PaymentService::class)->payCash($order, Money::fromDecimal('12'), $this->owner);
    $this->get('/pos/orders')->assertOk()->assertSee('Kofi')->assertSee('Paid');

    Livewire::test(OrdersList::class)
        ->set('search', 'kof')->assertSee('#'.$order->displayNumber())
        ->call('open', $order->id)->assertSee('Refund')
        ->call('reprint', $order->id)->assertSee('No receipt printer')
        ->call('startRefund', $payment->id)->set('refund.reason', 'Cold drink')->call('confirmRefund')
        ->assertSet('approval.permission', 'orders.refund')
        ->call('approve', '2468')->assertSee('Payment refunded')->assertSee('Refunded');

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('starts a delivery order from the home screen and edits its details on the order', function () {
    $this->actingAs($this->waiter);
    app(SettingsService::class)->set(TillSettings::DELIVERY_FEE, 1000);

    Livewire::test(OrderTypeScreen::class)
        ->call('startDelivery')->assertSet('delivery.fee', '10.00')->assertSee('New delivery')
        ->set('delivery.name', 'Ama')->set('delivery.phone', '')->set('delivery.address', 'Osu')
        ->call('openDelivery')->assertSee('phone number')
        ->set('delivery.phone', '0241234567')->set('delivery.fee', '12')
        ->call('openDelivery')->assertSet('approval.permission', 'orders.price_edit') // a changed fee is a price edit
        ->call('approve', '1234')->assertRedirect();

    $order = Order::withoutGlobalScopes()->where('type', 'delivery')->whereNull('voided_at')->latest('opened_at')->first();
    expect($order->customer_name)->toBe('Ama')->and($order->delivery_fee)->toBePesewas(1200)->and($order->delivery_status->value)->toBe('pending');

    Livewire::test(OrderScreen::class, ['order' => $order])
        ->assertSee('Ama · 0241234567')->assertSee('Delivery fee')->assertSee('GH₵ 12.00')
        ->call('editDelivery')->assertSet('modal', 'delivery')->set('delivery.address', 'Osu, blue gate')->call('saveDelivery')->assertSet('modal', null)->assertSee('Osu, blue gate');
});

it('assigns a rider on the deliveries board and the rider marks it out and delivered', function () {
    $rider = User::factory()->forBranch($this->branch)->withPin('4001')->create(['name' => 'Kwesi']);
    $rider->assignRole(Role::Rider->value);
    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::Delivery, $this->waiter);
    app(DeliveryService::class)->setDetails($order, 'Ama', '024', 'Osu');
    $orders->addItem($order, $this->menu['coke']);

    $this->actingAs($this->waiter);
    Livewire::test(DeliveriesBoard::class)
        ->assertSee('Ama')->assertSee('Preparing')
        ->call('startAssign', $order->id)->assertSee('Kwesi')
        ->call('assign', $order->id, $rider->id)->assertSee('Rider Kwesi assigned')->assertSee('Rider assigned');

    // A rider signing in lands on their own screen.
    auth()->logout();
    Livewire::test(PinLogin::class)->call('login', '4001')->assertRedirect(route('pos.rider'));
    $this->get('/pos/deliveries')->assertForbidden();

    Livewire::test(RiderScreen::class)
        ->assertSee('Ama')->assertSee('Osu')->assertSee('Collect GH₵ 12.00')
        ->call('markOut', $order->id)->assertSee('Out for delivery')
        ->call('markDelivered', $order->id)->assertDontSee('Ama');

    expect($order->fresh()->delivery_status->value)->toBe('delivered');
    $this->actingAs($this->owner)->get(route('pos.receipt', $order))->assertOk()->assertSee('Address: Osu')->assertSee('Kwesi');
});
