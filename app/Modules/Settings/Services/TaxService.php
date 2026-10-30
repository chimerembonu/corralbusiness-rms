<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\TaxComponent;
use App\Modules\Settings\Tax\GhanaTaxDefaults;
use App\Support\Tax\TaxComponentData;
use App\Support\Tax\TaxProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/** Loads the tax profile (components + inclusive flag) for a branch from the database. */
class TaxService
{
    public const SETTING_PRICES_INCLUDE_TAX = 'tax.prices_include_tax';

    public function __construct(
        private readonly TenantContext $context,
        private readonly SettingsService $settings,
    ) {}

    public function profileFor(Branch|string|null $branch = null): TaxProfile
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? $this->context->branchId());
        $tenantId = $this->context->tenantId();

        if ($tenantId === null) {
            return TaxProfile::none();
        }

        return Cache::remember("rms:tax:{$tenantId}:".($branchId ?? 'tenant'), now()->addHour(), function () use ($branchId) {
            $components = TaxComponent::query()
                ->active()
                ->forBranchOrTenant($branchId)
                ->orderBy('sort_order')
                ->get();

            // A branch-specific component with the same code replaces the tenant-wide one.
            $byCode = [];
            foreach ($components->sortBy(fn (TaxComponent $c) => $c->branch_id === null ? 0 : 1) as $component) {
                $byCode[$component->code] = $component->toData();
            }

            return new TaxProfile(
                array_values($byCode),
                (bool) $this->settings->get(self::SETTING_PRICES_INCLUDE_TAX, GhanaTaxDefaults::PRICES_INCLUDE_TAX, $branchId),
            );
        });
    }

    public function setPricesIncludeTax(bool $inclusive, bool $tenantWide = true): void
    {
        $this->settings->set(self::SETTING_PRICES_INCLUDE_TAX, $inclusive, tenantWide: $tenantWide);
        $this->forgetCache();
    }

    /** Create the default Ghana components for the current tenant (skips codes that already exist). */
    public function seedDefaults(): void
    {
        foreach (GhanaTaxDefaults::components() as $component) {
            TaxComponent::query()->firstOrCreate(
                ['code' => $component['code'], 'branch_id' => null],
                $component,
            );
        }

        $this->forgetCache();
    }

    public function forgetCache(): void
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId === null) {
            return;
        }

        Cache::forget("rms:tax:{$tenantId}:tenant");
        foreach (Branch::query()->pluck('id') as $branchId) {
            Cache::forget("rms:tax:{$tenantId}:{$branchId}");
        }
    }

    /** @return array<int, TaxComponentData> */
    public function defaults(): array
    {
        return array_map(fn (array $c) => new TaxComponentData(
            $c['code'], $c['name'], $c['rate_basis_points'], $c['kind'], $c['base'], $c['sort_order']
        ), GhanaTaxDefaults::components());
    }
}
