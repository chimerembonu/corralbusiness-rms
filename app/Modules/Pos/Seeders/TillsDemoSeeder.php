<?php

namespace App\Modules\Pos\Seeders;

use App\Modules\Pos\Models\Till;

/** Six tills: four at the counter, one at the bar, one on the terrace. Re-runnable. */
class TillsDemoSeeder
{
    public function run(): int
    {
        foreach (['Till 1 – Counter', 'Till 2 – Counter', 'Till 3 – Counter', 'Till 4 – Counter', 'Till 5 – Bar', 'Till 6 – Terrace'] as $i => $name) {
            Till::query()->updateOrCreate(['code' => 'T'.($i + 1)], ['name' => $name, 'is_active' => true]);
        }

        return Till::count();
    }
}
