<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Support\Modules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new restaurant (tenant) with its first branch, default Ghana tax
 * components and module switches. Used by the demo seeder and, later, HQ onboarding.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TaxService $taxes,
        private readonly SettingsService $settings,
    ) {}

    /** @param  array<string, mixed>  $tenantAttributes */
    public function provision(string $name, string $branchName = 'Main Branch', array $tenantAttributes = []): Branch
    {
        return DB::transaction(function () use ($name, $branchName, $tenantAttributes) {
            $tenant = Tenant::create(array_merge([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
            ], $tenantAttributes));

            $branch = $this->context->runAs($tenant, null, function () use ($tenant, $branchName) {
                $branch = Branch::create([
                    'tenant_id' => $tenant->id,
                    'name' => $branchName,
                    'code' => 'MAIN',
                    'timezone' => $tenant->timezone,
                ]);

                $this->taxes->seedDefaults();

                foreach (Modules::all() as $module) {
                    $this->settings->setModuleEnabled($module, Modules::isEnabled($module));
                }

                return $branch;
            });

            return $branch;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'restaurant';
        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
