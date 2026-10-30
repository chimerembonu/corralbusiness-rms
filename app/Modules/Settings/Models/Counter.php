<?php

namespace App\Modules\Settings\Models;

use App\Support\Models\BusinessModel;

class Counter extends BusinessModel
{
    /** Tenant-wide table: branch_id is only set when a row is specific to one branch. */
    protected bool $hasBranch = false;

    protected $fillable = ['branch_id', 'key', 'scope', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
