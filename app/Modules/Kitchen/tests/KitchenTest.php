<?php

use App\Modules\Kitchen\Enums\TicketStatus;
use App\Modules\Kitchen\Events\TicketChanged;
use App\Modules\Kitchen\Livewire\KitchenDisplay;
use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Kitchen\Services\TicketDocument;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->waiter = User::factory()->forBranch($this->branch)->create(['name' => 'Ama']);
    $this->waiter->assignRole(Role::Waiter->value);
    $this->cook = User::factory()->forBranch($this->branch)->create(['name' => 'Kwame']);
    $this->cook->assignRole(Role::Kitchen->value);
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    $this->kitchen = app(KitchenService::class);
});

function sendDemoOrder($test)
{
    $table = DiningTable::create(['name' => 'T3']);
    $order = $test->orders->open(OrderType::DineIn, $test->waiter, $table);
    $test->orders->addItem($order, $test->menu['jollof'], $test->menu['large'], 2, [$test->menu['chicken']->id => 1, $test->menu['plantain']->id => 2], 'no pepper');
    $test->orders->addItem($order, $test->menu['coke']);
    $test->orders->sendToKitchen($order, $test->waiter);

    return $order->fresh();
}

it('creates a ticket with the sent lines, their modifiers and notes, and broadcasts it', function () {
    Event::fake([TicketChanged::class]);
    $order = sendDemoOrder($this);

    $ticket = Ticket::sole();
    expect($ticket->order_number)->toBe($order->number)
        ->and($ticket->table_name)->toBe('T3')
        ->and($ticket->server_name)->toBe('Ama')
        ->and($ticket->status)->toBe(TicketStatus::New)
        ->and($ticket->items)->toHaveCount(2)
        ->and($ticket->items[0]->name)->toBe('Jollof Rice (Large)')
        ->and($ticket->items[0]->quantity)->toBe(2)
        ->and($ticket->items[0]->modifiers)->toBe(['Chicken', '2 × Extra plantain'])
        ->and($ticket->items[0]->notes)->toBe('no pepper')
        ->and($ticket->ageBucket())->toBe('fresh');

    Event::assertDispatched(TicketChanged::class, fn ($e) => $e->change === 'created' && $e->broadcastOn()[0]->name === 'private-kitchen.'.$this->branch->id);

    // Sending again only creates a ticket for the new lines.
    $this->orders->addItem($order, $this->menu['water']);
    $this->orders->sendToKitchen($order->fresh());
    expect(Ticket::count())->toBe(2)->and(Ticket::orderByDesc('id')->first()->items)->toHaveCount(1);
});

it('bumps new → in progress → done, recalls, and colours tickets by age', function () {
    sendDemoOrder($this);
    $ticket = Ticket::sole();

    $this->kitchen->bump($ticket, $this->cook);
    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)->and($ticket->fresh()->started_at)->not->toBeNull();

    $this->kitchen->bump($ticket->fresh(), $this->cook);
    expect($ticket->fresh()->status)->toBe(TicketStatus::Done)
        ->and($ticket->fresh()->bumped_by_id)->toBe($this->cook->id)
        ->and($this->kitchen->activeTickets($this->branch->id))->toHaveCount(0)
        ->and($this->kitchen->recentlyDone($this->branch->id))->toHaveCount(1);

    $this->kitchen->recall($ticket->fresh(), $this->cook);
    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)->and($this->kitchen->activeTickets($this->branch->id))->toHaveCount(1);

    $this->travel(6)->minutes();
    expect($ticket->fresh()->ageBucket())->toBe('warn');
    $this->travel(5)->minutes();
    expect($ticket->fresh()->ageBucket())->toBe('late');
});

it('strikes through a line voided after sending', function () {
    $order = sendDemoOrder($this);
    $line = $order->items->firstWhere('name', 'Coca-Cola');

    $this->orders->voidItem($order, $line, 'Changed mind', $this->manager);

    expect(Ticket::sole()->items->firstWhere('name', 'Coca-Cola')->is_voided)->toBeTrue();
});

it('prints a big simple kitchen ticket', function () {
    sendDemoOrder($this);
    $doc = TicketDocument::fromTicket(Ticket::sole());
    $text = collect($doc->lines())->pluck('text')->filter()->implode("\n");

    expect($text)->toContain('Table T3', '2 x Jollof Rice (Large)', '+ Chicken', '+ 2 × Extra plantain', '** no pepper **', '1 x Coca-Cola');
});

it('shows the kitchen screen to kitchen staff with live tickets and keyboard bumping', function () {
    sendDemoOrder($this);
    $this->actingAs($this->cook);

    $this->get('/kitchen')->assertOk()->assertSee('Jollof Rice (Large)')->assertSee('no pepper')->assertSee('Start')->assertSee('wire:poll', false);

    Livewire::test(KitchenDisplay::class)
        ->assertSee('#0001')->assertSee('Table T3')
        ->call('bumpIndex', 1)                               // key "1" → start
        ->assertSee('In progress')
        ->call('bumpIndex', 1)                               // key "1" again → done
        ->assertDontSee('Table T3')
        ->assertSee('Recently done')
        ->call('recall', null)                               // key "R"
        ->assertSee('Table T3');

    expect(Ticket::sole()->status)->toBe(TicketStatus::InProgress);
});

it('keeps guests and waiters off the kitchen screen', function () {
    $this->get('/kitchen')->assertRedirect('/pos/login');
    $this->actingAs($this->waiter)->get('/kitchen')->assertForbidden(); // waiters and cashiers don't use the kitchen screen (owner, 4 Nov 2026)
    $nobody = User::factory()->forBranch($this->branch)->create();
    $this->actingAs($nobody)->get('/kitchen')->assertForbidden();
});

it('authorises the private kitchen channel per branch', function () {
    $rule = Broadcast::getChannels()['kitchen.{branchId}'];
    $otherBranch = provisionTenant('Other');

    expect($rule($this->cook, $this->branch->id))->toBeTrue()
        ->and($rule($this->cook, $otherBranch->id))->toBeFalse()
        ->and($rule(User::factory()->forBranch($this->branch)->create(), $this->branch->id))->toBeFalse(); // no kitchen permission
});
