<?php

namespace App\Modules\Settings\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;

/** Per-tenant module on/off switch (feature flag). */
class ModuleSetting extends BusinessModel
{
    use Auditable;

    protected bool $hasBranch = false;

    protected $fillable = ['module', 'enabled', 'settings'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['enabled' => true];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }
}
