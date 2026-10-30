<?php

namespace App\Support\Models;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds tenant_id / branch_id behaviour (CLAUDE.md §3.2):
 *  - rows are automatically filtered to the current tenant,
 *  - tenant_id and branch_id are filled from the context when a row is created.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id) && $context->tenantId()) {
                $model->tenant_id = $context->tenantId();
            }

            if ($model->hasBranchColumn() && empty($model->branch_id) && $context->branchId()) {
                $model->branch_id = $context->branchId();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Only rows of one branch (or the current branch when none is given). */
    public function scopeForBranch(Builder $query, Branch|string|null $branch = null): Builder
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? app(TenantContext::class)->branchId());

        return $query->where($this->qualifyColumn('branch_id'), $branchId);
    }

    /** Rows of one branch plus tenant-wide rows (branch_id null), e.g. tax components or settings. */
    public function scopeForBranchOrTenant(Builder $query, Branch|string|null $branch = null): Builder
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? app(TenantContext::class)->branchId());

        return $query->where(function (Builder $q) use ($branchId) {
            $q->whereNull($this->qualifyColumn('branch_id'));
            if ($branchId) {
                $q->orWhere($this->qualifyColumn('branch_id'), $branchId);
            }
        });
    }

    /** Escape hatch for HQ/sync code that must see every tenant. */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    protected function hasBranchColumn(): bool
    {
        return property_exists($this, 'hasBranch') ? (bool) $this->hasBranch : true;
    }
}
