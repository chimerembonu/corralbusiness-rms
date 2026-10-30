<?php

use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Menu\Support\Availability;
use Carbon\Carbon;

it('is always available when no rule is set', function () {
    expect(Availability::fromArray(null)->isAvailableAt(now()))->toBeTrue()
        ->and(Availability::fromArray(['days' => [], 'from' => null, 'to' => null])->isAlways())->toBeTrue();
});

it('limits by day of week and time window', function () {
    $breakfast = Availability::fromArray(['days' => [1, 2, 3, 4, 5], 'from' => '06:00', 'to' => '11:00']);

    expect($breakfast->isAvailableAt(Carbon::parse('2026-11-02 08:30')))->toBeTrue()   // Monday
        ->and($breakfast->isAvailableAt(Carbon::parse('2026-11-02 11:00')))->toBeTrue()
        ->and($breakfast->isAvailableAt(Carbon::parse('2026-11-02 11:01')))->toBeFalse()
        ->and($breakfast->isAvailableAt(Carbon::parse('2026-11-01 08:30')))->toBeFalse(); // Sunday
});

it('handles windows that cross midnight', function () {
    $late = Availability::fromArray(['days' => null, 'from' => '18:00', 'to' => '02:00']);

    expect($late->isAvailableAt(Carbon::parse('2026-11-02 23:00')))->toBeTrue()
        ->and($late->isAvailableAt(Carbon::parse('2026-11-03 01:30')))->toBeTrue()
        ->and($late->isAvailableAt(Carbon::parse('2026-11-03 12:00')))->toBeFalse();
});

it('normalises availability on the model and respects the category window', function () {
    provisionTenant();
    $category = Category::create(['name' => 'Breakfast', 'availability' => ['days' => ['1', '2'], 'from' => '06:00', 'to' => '11:00']]);
    $koko = Item::create(['category_id' => $category->id, 'name' => 'Hausa Koko', 'price' => 1500, 'availability' => ['days' => [], 'from' => '', 'to' => '']]);
    $menu = app(MenuService::class);

    expect($koko->fresh()->availability)->toBeNull()
        ->and($category->fresh()->availability)->toBe(['days' => [1, 2], 'from' => '06:00', 'to' => '11:00'])
        ->and($menu->isOrderable($koko, Carbon::parse('2026-11-02 08:00')))->toBeTrue()
        ->and($menu->isOrderable($koko, Carbon::parse('2026-11-02 13:00')))->toBeFalse()
        ->and($category->availabilityRule()->describe())->toBe('Mon, Tue · 06:00–11:00');
});

it('leaves inactive and sold-out items out of the POS catalog', function () {
    provisionTenant();
    $category = Category::create(['name' => 'Drinks']);
    Item::create(['category_id' => $category->id, 'name' => 'Coke', 'price' => 1000]);
    Item::create(['category_id' => $category->id, 'name' => 'Fanta', 'price' => 1000, 'is_sold_out' => true]);
    Item::create(['category_id' => $category->id, 'name' => 'Old drink', 'price' => 1000, 'is_active' => false]);
    Category::create(['name' => 'Hidden', 'is_active' => false]);

    $catalog = app(MenuService::class)->catalogFor();

    expect($catalog)->toHaveCount(1)
        ->and($catalog->first()->items->pluck('name')->all())->toBe(['Coke']);
});
