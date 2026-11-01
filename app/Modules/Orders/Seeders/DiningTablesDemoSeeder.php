<?php

namespace App\Modules\Orders\Seeders;

use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;

/** 12 demo tables laid out on the floor plan: 8 indoors, 4 on the terrace. Re-runnable. */
class DiningTablesDemoSeeder
{
    public function run(): int
    {
        $indoor = FloorSection::query()->updateOrCreate(['name' => 'Indoor'], ['color' => '#10b981', 'sort_order' => 1, 'is_active' => true]);
        $terrace = FloorSection::query()->updateOrCreate(['name' => 'Terrace'], ['color' => '#f59e0b', 'sort_order' => 2, 'is_active' => true]);

        // name, section, seats, shape, x, y, width, height  (grid is 20 × 12 cells)
        $tables = [
            ['T1', $indoor, 2, 'round', 1, 1, 2, 2], ['T2', $indoor, 2, 'round', 5, 1, 2, 2],
            ['T3', $indoor, 4, 'square', 9, 1, 2, 2], ['T4', $indoor, 4, 'square', 13, 1, 2, 2],
            ['T5', $indoor, 4, 'square', 1, 5, 2, 2], ['T6', $indoor, 6, 'rect', 5, 5, 3, 2],
            ['T7', $indoor, 6, 'rect', 10, 5, 3, 2], ['T8', $indoor, 8, 'rect', 1, 9, 4, 2],
            ['P1', $terrace, 4, 'square', 1, 1, 2, 2], ['P2', $terrace, 4, 'square', 5, 1, 2, 2],
            ['P3', $terrace, 6, 'rect', 1, 5, 3, 2], ['P4', $terrace, 6, 'rect', 6, 5, 3, 2],
        ];

        foreach ($tables as $i => [$name, $section, $seats, $shape, $x, $y, $w, $h]) {
            DiningTable::query()->updateOrCreate(['name' => $name], [
                'section_id' => $section->id, 'seats' => $seats, 'shape' => $shape,
                'pos_x' => $x, 'pos_y' => $y, 'width' => $w, 'height' => $h,
                'sort_order' => $i, 'is_active' => true,
            ]);
        }

        return DiningTable::count();
    }
}
