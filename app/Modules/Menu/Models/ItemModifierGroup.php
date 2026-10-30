<?php

namespace App\Modules\Menu\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Pivot: which modifier groups an item offers. */
class ItemModifierGroup extends Pivot
{
    use HasUuids;

    protected $table = 'menu_item_modifier_groups';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (ItemModifierGroup $pivot) {
            $pivot->tenant_id ??= app(TenantContext::class)->tenantId();
        });
    }
}
