<?php

namespace App\Support\Tenancy;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;

/**
 * A store server serves ONE restaurant branch. Which one is set in .env
 * (RMS_STORE_TENANT / RMS_STORE_BRANCH); when unset and only one exists, that one is used.
 */
class StoreTenantResolver
{
    public function tenant(): ?Tenant
    {
        $slug = config('rms.store.tenant');

        if ($slug) {
            return Tenant::query()->where('slug', $slug)->first();
        }

        return Tenant::query()->count() === 1 ? Tenant::query()->first() : null;
    }

    public function branch(?Tenant $tenant = null): ?Branch
    {
        $tenant ??= $this->tenant();

        if (! $tenant) {
            return null;
        }

        $query = Branch::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('is_active', true);
        $code = config('rms.store.branch');

        return ($code ? (clone $query)->where('code', $code)->first() : null)
            ?? $query->orderBy('created_at')->first();
    }
}
