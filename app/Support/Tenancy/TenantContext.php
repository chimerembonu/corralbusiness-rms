<?php

namespace App\Support\Tenancy;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Holds "which restaurant (tenant) and which branch is this request working for".
 *
 * Set by middleware from the logged-in user (or by a PIN session on the POS),
 * read by the BelongsToTenant trait to scope queries and fill tenant_id/branch_id.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Branch $branch = null;

    public function set(?Tenant $tenant, ?Branch $branch = null): void
    {
        $this->tenant = $tenant;
        $this->branch = $branch;
    }

    public function setBranch(?Branch $branch): void
    {
        $this->branch = $branch;

        if ($branch && ! $this->tenant) {
            $this->tenant = $branch->tenant;
        }
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->branch = null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function tenantId(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function branchId(): ?string
    {
        return $this->branch?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * A record reached by its ID in a URL must belong to the restaurant and branch this request works
     * in; anything else is reported as "not found" (security review H4). Tenant-wide records (no
     * branch column, e.g. customers) only need the restaurant to match.
     */
    public function assertOwns(Model $record): void
    {
        $tenantOk = $this->tenantId() !== null && $record->getAttribute('tenant_id') === $this->tenantId();
        $hasBranch = array_key_exists('branch_id', $record->getAttributes());
        $branchOk = ! $hasBranch || $record->getAttribute('branch_id') === null || $record->getAttribute('branch_id') === $this->branchId();

        abort_unless($tenantOk && $branchOk, 404);
    }

    /** Run a callback as a given tenant/branch, then restore the previous context. */
    public function runAs(?Tenant $tenant, ?Branch $branch, callable $callback): mixed
    {
        [$previousTenant, $previousBranch] = [$this->tenant, $this->branch];
        $this->set($tenant, $branch);

        try {
            return $callback();
        } finally {
            $this->set($previousTenant, $previousBranch);
        }
    }
}
