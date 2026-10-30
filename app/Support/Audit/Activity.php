<?php

namespace App\Support\Audit;

use App\Support\Tenancy\TenantContext;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/** Activity log row with tenant_id / branch_id stamped automatically. */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            $context = app(TenantContext::class);

            $activity->tenant_id ??= $activity->subject?->tenant_id ?? $context->tenantId();
            $activity->branch_id ??= $activity->subject?->branch_id ?? $context->branchId();
        });
    }
}
