<?php

namespace App\Modules\Kitchen\Seeders;

use App\Modules\Kitchen\Models\Route;
use App\Modules\Kitchen\Models\Station;
use App\Modules\Kitchen\Services\RoutingService;
use App\Modules\Menu\Models\Category;

/** Three stations: Grill, Fry, Bar. Routing per station arrives in Phase 1.4. */
class StationsDemoSeeder
{
    public function run(): int
    {
        $stations = [];
        foreach ([['GRILL', 'Grill', '#dc2626', true], ['FRY', 'Fry', '#f59e0b', false], ['BAR', 'Bar', '#2563eb', false]] as $i => [$code, $name, $color, $default]) {
            $stations[$code] = Station::query()->updateOrCreate(['code' => $code], ['name' => $name, 'color' => $color, 'sort_order' => $i, 'is_active' => true, 'is_default' => $default]);
        }

        // Routing: grills, soups and local dishes → Grill (default); rice and sides → Fry; drinks → Bar.
        $routing = app(RoutingService::class);
        $byCategory = ['Rice Dishes' => 'FRY', 'Sides & Snacks' => 'FRY', 'Breakfast' => 'FRY', 'Soft Drinks & Juices' => 'BAR', 'Beers & Spirits' => 'BAR'];
        foreach (Category::query()->whereIn('name', array_keys($byCategory))->get() as $category) {
            $routing->route(Route::CATEGORY, $category->id, $stations[$byCategory[$category->name]]->id);
        }

        return Station::count();
    }
}
