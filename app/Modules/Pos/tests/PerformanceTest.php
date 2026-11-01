<?php

use App\Modules\Kitchen\Livewire\KitchenDisplay;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Guards against N+1 regressions on the screens staff tap all day (Phase 3.3 load test). */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
});

function countQueries(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('renders the order screen and adds an item with a bounded number of queries', function () {
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->owner);
    app(MenuService::class)->catalogFor($this->branch->id); // warm the catalog cache, as a busy till would have

    $render = countQueries(fn () => Livewire::test(OrderScreen::class, ['order' => $order])->assertSee('Coca-Cola'));
    expect($render)->toBeLessThanOrEqual(25);

    $add = countQueries(fn () => Livewire::test(OrderScreen::class, ['order' => $order])->call('tapItem', $this->menu['coke']->id));
    expect($add)->toBeLessThanOrEqual(45);
});

it('renders the kitchen screen with a bounded number of queries', function () {
    $orders = app(OrderService::class);
    foreach (range(1, 5) as $i) {
        $order = $orders->open(OrderType::Takeaway, $this->owner);
        $orders->addItem($order, $this->menu['coke'], quantity: $i);
        $orders->sendToKitchen($order, $this->owner);
    }

    $queries = countQueries(fn () => Livewire::test(KitchenDisplay::class)->assertSee('Coca-Cola'));
    expect($queries)->toBeLessThanOrEqual(20);
});

it('serves the till catalog from cache and clears it when the menu changes', function () {
    $menu = app(MenuService::class);
    $menu->catalogFor($this->branch->id);
    expect(countQueries(fn () => $menu->catalogFor($this->branch->id)))->toBe(0);

    $menu->setSoldOut($this->menu['coke'], true);
    $names = $menu->catalogFor($this->branch->id)->flatMap(fn ($c) => $c->items)->pluck('name');
    expect($names)->not->toContain('Coca-Cola')->and($names)->toContain('Bottled Water');

    $this->menu['water']->update(['name' => 'Voltic Water']);
    expect($menu->catalogFor($this->branch->id)->flatMap(fn ($c) => $c->items)->pluck('name'))->toContain('Voltic Water');
});
