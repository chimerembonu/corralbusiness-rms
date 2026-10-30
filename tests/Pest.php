<?php

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\TenantProvisioner;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Every test in tests/Feature, tests/Unit and each module's tests/ folder
| (app/Modules/<Name>/tests) gets the Laravel TestCase and a fresh SQLite
| in-memory database (see phpunit.xml).
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', dirname(__DIR__).'/app/Modules');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

expect()->extend('toBePesewas', function (int $pesewas) {
    return $this->amount->toBe($pesewas);
});

/** Creates a restaurant with one branch and default Ghana taxes, and makes it the current tenant. */
function provisionTenant(string $name = 'Test Chop Bar'): Branch
{
    $branch = app(TenantProvisioner::class)->provision($name);
    Tenancy::set($branch->tenant, $branch);

    return $branch;
}

function seedRoles(): void
{
    (new RolesAndPermissionsSeeder)->run();
}
