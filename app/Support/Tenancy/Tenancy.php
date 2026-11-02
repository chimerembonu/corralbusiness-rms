<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(?\App\Modules\Settings\Models\Tenant $tenant, ?\App\Modules\Settings\Models\Branch $branch = null)
 * @method static void setBranch(?\App\Modules\Settings\Models\Branch $branch)
 * @method static void forget()
 * @method static ?\App\Modules\Settings\Models\Tenant tenant()
 * @method static ?\App\Modules\Settings\Models\Branch branch()
 * @method static ?string tenantId()
 * @method static ?string branchId()
 * @method static bool hasTenant()
 * @method static void assertOwns(\Illuminate\Database\Eloquent\Model $record)
 * @method static mixed runAs(?\App\Modules\Settings\Models\Tenant $tenant, ?\App\Modules\Settings\Models\Branch $branch, callable $callback)
 *
 * @see TenantContext
 */
class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }
}
