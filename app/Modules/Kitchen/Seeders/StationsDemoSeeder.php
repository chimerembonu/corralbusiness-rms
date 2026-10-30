<?php

namespace App\Modules\Kitchen\Seeders;

use App\Modules\Kitchen\Models\Station;

/** Three stations: Grill, Fry, Bar. Routing per station arrives in Phase 1.4. */
class StationsDemoSeeder
{
    public function run(): int
    {
        foreach ([['GRILL', 'Grill', '#dc2626'], ['FRY', 'Fry', '#f59e0b'], ['BAR', 'Bar', '#2563eb']] as $i => [$code, $name, $color]) {
            Station::query()->updateOrCreate(['code' => $code], ['name' => $name, 'color' => $color, 'sort_order' => $i, 'is_active' => true]);
        }

        return Station::count();
    }
}
