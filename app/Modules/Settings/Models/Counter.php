<?php

namespace App\Modules\Settings\Models;

use App\Support\Models\BusinessModel;

class Counter extends BusinessModel
{
    protected $fillable = ['branch_id', 'key', 'scope', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
