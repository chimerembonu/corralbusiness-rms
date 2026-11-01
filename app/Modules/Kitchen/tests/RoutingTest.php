<?php

use App\Modules\Kitchen\Enums\TicketStatus;
use App\Modules\Kitchen\Filament\Pages\KitchenRouting;
use App\Modules\Kitchen\Livewire\ExpoDisplay;
use App\Modules\Kitchen\Livewire\KitchenDisplay;
use App\Modules\Kitchen\Models\Route;
use App\Modules\Kitchen\Models\Station;
use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Kitchen\Services\RoutingService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

beforeEach(function () {
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->grill = Station::create(['name' => 'Grill', 'code' => 'grill', 'sort_order' => 1, 'is_default' => true]);
    $this->bar = Station::create(['name' => 'Bar', 'code' => 'BAR', 'sort_order' => 2]);
    $this->routing = app(RoutingService::class);
    $this->kitchen = app(KitchenService::class);
    $this->orders = app(OrderService::class);
});

it('resolves stations from item rules, category rules, branch overrides and the default', function () {
    expect($this->grill->fresh()->code)->toBe('GRILL')
        ->and($this->routing->stationFor($this->menu['coke']->id, $this->menu['drinks']->id)->id)->toBe($this->grill->id); // default

    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, $this->bar->id);
    expect($this->routing->stationFor($this->menu['coke']->id, $this->menu['drinks']->id)->id)->toBe($this->bar->id)
        ->and($this->routing->stationFor($this->menu['jollof']->id, $this->menu['rice']->id)->id)->toBe($this->grill->id);

    // An item rule beats its category; a branch rule beats the tenant rule.
    $this->routing->route(Route::ITEM, $this->menu['water']->id, $this->grill->id);
    expect($this->routing->stationFor($this->menu['water']->id, $this->menu['drinks']->id)->id)->toBe($this->grill->id);
    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, $this->grill->id, $this->branch->id);
    expect($this->routing->stationFor($this->menu['coke']->id, $this->menu['drinks']->id)->id)->toBe($this->grill->id);
    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, null, $this->branch->id);
    expect($this->routing->stationFor($this->menu['coke']->id, $this->menu['drinks']->id)->id)->toBe($this->bar->id);

    // Only one default per branch.
    $this->bar->update(['is_default' => true]);
    expect($this->grill->fresh()->is_default)->toBeFalse();
});

it('creates one ticket per station when an order is sent and prints each on its own printer', function () {
    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, $this->bar->id);
    $grillPrinter = Printer::create(['name' => 'Grill printer', 'driver' => 'browser', 'prints_kitchen_tickets' => true, 'station_ids' => [$this->grill->id]]);
    $anyPrinter = Printer::create(['name' => 'Pass printer', 'driver' => 'browser', 'prints_kitchen_tickets' => true]);

    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1]);
    $this->orders->addItem($order, $this->menu['coke'], quantity: 2);
    $this->orders->addItem($order, $this->menu['water']);
    $this->orders->sendToKitchen($order, $this->cashier);

    $tickets = Ticket::query()->where('order_id', $order->id)->with('items')->get()->keyBy('station_id');
    expect($tickets)->toHaveCount(2)
        ->and($tickets[$this->grill->id]->items->pluck('name')->all())->toBe(['Jollof Rice (Regular)'])
        ->and($tickets[$this->bar->id]->items->pluck('name')->sort()->values()->all())->toBe(['Bottled Water', 'Coca-Cola'])
        ->and(PrintJob::query()->where('printer_id', $grillPrinter->id)->count())->toBe(1)
        ->and(PrintJob::query()->where('printer_id', $anyPrinter->id)->count())->toBe(2);

    expect($this->kitchen->activeTickets(null, $this->bar->id))->toHaveCount(1)
        ->and($this->kitchen->activeTickets())->toHaveCount(2)
        ->and($this->kitchen->allDayCounts(null, $this->bar->id)->pluck('quantity', 'name')->all())->toBe(['Coca-Cola' => 2, 'Bottled Water' => 1]);

    // A second order adds to the all-day count.
    $second = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($second, $this->menu['coke'], quantity: 3);
    $this->orders->sendToKitchen($second, $this->cashier);
    expect($this->kitchen->allDayCounts(null, $this->bar->id)->first())->toBe(['name' => 'Coca-Cola', 'quantity' => 5]);
});

it('shows orders on the expo board and clears them when served', function () {
    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, $this->bar->id);
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1]);
    $this->orders->addItem($order, $this->menu['coke']);
    $this->orders->sendToKitchen($order, $this->cashier);

    $board = $this->kitchen->expoBoard();
    expect($board)->toHaveCount(1)->and($board[0]['ready'])->toBeFalse()->and($board[0]['tickets'])->toHaveCount(2);

    $grillTicket = Ticket::query()->where('order_id', $order->id)->where('station_id', $this->grill->id)->first();
    $this->kitchen->done($grillTicket, $this->cashier);
    expect($this->kitchen->expoBoard()[0]['ready'])->toBeFalse();

    $barTicket = Ticket::query()->where('order_id', $order->id)->where('station_id', $this->bar->id)->first();
    $this->kitchen->done($barTicket, $this->cashier);
    expect($this->kitchen->expoBoard()[0]['ready'])->toBeTrue()
        ->and($this->kitchen->recentlyDone())->toHaveCount(2);

    expect($this->kitchen->serve($order->id, $this->cashier))->toBe(2)
        ->and($this->kitchen->expoBoard())->toHaveCount(0)
        ->and($this->kitchen->recentlyDone())->toHaveCount(0)
        ->and($grillTicket->fresh()->served_at)->not->toBeNull();

    // Recalling a served ticket brings the order back to the pass.
    $this->kitchen->recall($grillTicket->fresh(), $this->cashier);
    expect($this->kitchen->expoBoard())->toHaveCount(1)->and($grillTicket->fresh()->status)->toBe(TicketStatus::InProgress);
});

it('keeps routing rules per restaurant', function () {
    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, $this->bar->id);
    $other = provisionTenant('Other');
    Station::create(['name' => 'Kitchen', 'code' => 'K', 'is_default' => true]);
    expect(Route::query()->count())->toBe(0)->and($this->routing->map())->toBe([]);
    Tenancy::set($this->branch->tenant, $this->branch);
    expect(Route::query()->count())->toBe(1);
});

it('filters the kitchen screen by station, shows the all-day panel, and clears orders from the expo screen', function () {
    seedRoles();
    $cook = User::factory()->forBranch($this->branch)->create();
    $cook->assignRole(Role::Kitchen->value);
    $this->routing->route(Route::CATEGORY, $this->menu['drinks']->id, $this->bar->id);
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['jollof'], $this->menu['regular'], 1, [$this->menu['chicken']->id => 1]);
    $this->orders->addItem($order, $this->menu['coke'], quantity: 2);
    $this->orders->sendToKitchen($order, $this->cashier);

    $this->actingAs($cook);
    Livewire::test(KitchenDisplay::class)
        ->assertSee('All stations')->assertSee('Jollof Rice')->assertSee('Coca-Cola')
        ->call('selectStation', 'bar')->assertSet('stationCode', 'BAR')
        ->assertSee('Coca-Cola')->assertDontSee('Jollof Rice')->assertSee('All day')->assertSee('font-black text-emerald-300">2</span>', false)
        ->call('bumpIndex', 1)->call('bumpIndex', 1)   // start, then done
        ->assertSee('Recently done');

    $this->get('/kitchen?station=GRILL')->assertOk()->assertSee('Jollof Rice')->assertDontSee('Coca-Cola');

    Livewire::test(ExpoDisplay::class)
        ->assertSee('#'.$order->displayNumber())->assertSee('Waiting on Grill')
        ->call('bumpTicket', Ticket::query()->where('order_id', $order->id)->where('station_id', $this->grill->id)->value('id'))
        ->call('bumpTicket', Ticket::query()->where('order_id', $order->id)->where('station_id', $this->grill->id)->value('id'))
        ->assertDontSee('Waiting on')->assertSee('Served')
        ->call('serve', $order->id)->assertDontSee('#'.$order->displayNumber());
});

it('lets the owner manage stations and routing in the back office', function () {
    seedRoles();
    $owner = User::factory()->forBranch($this->branch)->create();
    $owner->assignRole(Role::Owner->value);
    $this->actingAs($owner);

    $this->get('/admin/stations')->assertOk()->assertSee('Grill')->assertSee('Bar');
    $this->get('/admin/kitchen-routing')->assertOk()->assertSee('Drinks')->assertSee('Default station: Grill');

    Livewire::test(KitchenRouting::class)
        ->call('setRoute', 'category', $this->menu['drinks']->id, $this->bar->id)
        ->call('setRoute', 'item', $this->menu['water']->id, $this->grill->id)
        ->set('search', 'water')->assertSee('Bottled Water')->assertDontSee('Jollof');

    Tenancy::set($this->branch->tenant, $this->branch);
    expect($this->routing->stationFor($this->menu['coke']->id, $this->menu['drinks']->id)->id)->toBe($this->bar->id)
        ->and($this->routing->stationFor($this->menu['water']->id, $this->menu['drinks']->id)->id)->toBe($this->grill->id);

    Livewire::test(KitchenRouting::class)->call('setRoute', 'item', $this->menu['water']->id, '');
    Tenancy::set($this->branch->tenant, $this->branch);
    expect($this->routing->stationFor($this->menu['water']->id, $this->menu['drinks']->id)->id)->toBe($this->bar->id);
});
