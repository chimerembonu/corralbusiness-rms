<?php

use App\Modules\Menu\Events\ItemSoldOutChanged;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Services\MenuService;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    provisionTenant();
    $this->item = Item::create(['category_id' => Category::create(['name' => 'Grills'])->id, 'name' => 'Tilapia', 'price' => 9500]);
});

it('86s an item, fires the event and writes to the outbox', function () {
    Event::fake([ItemSoldOutChanged::class]);

    app(MenuService::class)->setSoldOut($this->item, true, 'user-1');

    expect($this->item->fresh()->is_sold_out)->toBeTrue()
        ->and($this->item->fresh()->sold_out_at)->not->toBeNull();
    Event::assertDispatched(ItemSoldOutChanged::class, fn ($e) => $e->soldOut && $e->byUserId === 'user-1');
});

it('records the change in the outbox for sync', function () {
    app(MenuService::class)->setSoldOut($this->item, true);

    expect(OutboxEvent::where('event_type', 'menu.item.sold_out')->count())->toBe(1);

    app(MenuService::class)->setSoldOut($this->item, true); // no change → no second event
    expect(OutboxEvent::count())->toBe(1);
});

it('puts everything back on sale at the start of the day', function () {
    app(MenuService::class)->setSoldOut($this->item, true);

    expect(app(MenuService::class)->resetSoldOut())->toBe(1)
        ->and($this->item->fresh()->is_sold_out)->toBeFalse()
        ->and(OutboxEvent::where('event_type', 'menu.item.available')->count())->toBe(1);
});
