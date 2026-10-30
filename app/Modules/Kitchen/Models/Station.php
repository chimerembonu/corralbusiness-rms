<?php

namespace App\Modules\Kitchen\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;

class Station extends BusinessModel
{
    use Auditable;

    protected $table = 'kitchen_stations';

    protected $fillable = ['branch_id', 'name', 'code', 'color', 'sort_order', 'is_active'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
