<?php

namespace App\Modules\Orders\Seeders;

use App\Modules\Orders\Models\DiningTable;

/** 12 demo tables: 8 indoors, 4 on the terrace. Re-runnable. */
class DiningTablesDemoSeeder
{
    public function run(): int
    {
        $tables = [
            ['T1', 'Indoor', 2], ['T2', 'Indoor', 2], ['T3', 'Indoor', 4], ['T4', 'Indoor', 4],
            ['T5', 'Indoor', 4], ['T6', 'Indoor', 6], ['T7', 'Indoor', 6], ['T8', 'Indoor', 8],
            ['P1', 'Terrace', 4], ['P2', 'Terrace', 4], ['P3', 'Terrace', 6], ['P4', 'Terrace', 6],
        ];

        foreach ($tables as $i => [$name, $section, $seats]) {
            DiningTable::query()->updateOrCreate(['name' => $name], ['section' => $section, 'seats' => $seats, 'sort_order' => $i, 'is_active' => true]);
        }

        return DiningTable::count();
    }
}
