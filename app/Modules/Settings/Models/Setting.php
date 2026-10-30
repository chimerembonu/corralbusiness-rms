<?php

namespace App\Modules\Settings\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;

/** A single key/value setting for a tenant (branch_id null) or one branch. */
class Setting extends BusinessModel
{
    use Auditable;

    /** Tenant-wide table: branch_id is only set when a row is specific to one branch. */
    protected bool $hasBranch = false;

    protected $fillable = ['branch_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
