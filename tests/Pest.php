<?php

use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ModifierGroup;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\TenantProvisioner;
use App\Support\Licence\Licence;
use App\Support\Licence\LicenceCodec;
use App\Support\Licence\LicenceService;
use App\Support\Tenancy\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
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

/**
 * A licence code signed with a throw-away test key (the real Corral Systems key is never used in tests).
 * The test key is trusted for this test only.
 *
 * @param  array<string, mixed>  $details  overrides: plan, branches, issued, expires, tenant, extras…
 */
function testLicenceCode(array $details = []): string
{
    static $pair;
    $pair ??= sodium_crypto_sign_keypair();
    config(['licence.public_keys' => ['test' => base64_encode(sodium_crypto_sign_publickey($pair))]]);

    return LicenceCodec::issue(array_merge([
        'id' => 'L-TEST-0001',
        'restaurant' => 'Test Chop Bar',
        'plan' => 'pro',
        'branches' => 1,
        'issued' => now()->toDateString(),
        'expires' => now()->addYear()->toDateString(),
    ], $details), base64_encode(sodium_crypto_sign_secretkey($pair)), 'test');
}

/** Installs a test licence for the current restaurant. */
function installTestLicence(string $plan = 'pro', array $details = []): Licence
{
    return app(LicenceService::class)->install(testLicenceCode(['plan' => $plan] + $details));
}

function seedRoles(): void
{
    (new RolesAndPermissionsSeeder)->run();
}

/**
 * A small menu for order tests (prices in pesewas):
 *  jollof  45.00 (Regular, default) / 60.00 (Large); groups: protein (required, pick 1: chicken +15, beef +15)
 *          and extras (optional, up to 3, quantities allowed: plantain +8)
 *  coke    10.00, no options;   water 5.00, not taxable
 *
 * @return array<string, Model>
 */
function demoMenu(): array
{
    $rice = Category::create(['name' => 'Rice']);
    $drinks = Category::create(['name' => 'Drinks']);

    $jollof = Item::create(['category_id' => $rice->id, 'name' => 'Jollof Rice', 'code' => 'JOL', 'price' => 4500]);
    $regular = $jollof->variants()->create(['name' => 'Regular', 'price' => 4500, 'is_default' => true]);
    $large = $jollof->variants()->create(['name' => 'Large', 'price' => 6000]);

    $protein = ModifierGroup::create(['name' => 'Choose a protein', 'min_select' => 1, 'max_select' => 1]);
    $chicken = $protein->modifiers()->create(['name' => 'Chicken', 'price' => 1500]);
    $beef = $protein->modifiers()->create(['name' => 'Beef', 'price' => 1500]);
    $extras = ModifierGroup::create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 3, 'allow_quantity' => true]);
    $plantain = $extras->modifiers()->create(['name' => 'Extra plantain', 'price' => 800]);
    $jollof->modifierGroups()->sync([$protein->id => ['sort_order' => 0], $extras->id => ['sort_order' => 1]]);

    $coke = Item::create(['category_id' => $drinks->id, 'name' => 'Coca-Cola', 'code' => 'COK', 'price' => 1000]);
    $water = Item::create(['category_id' => $drinks->id, 'name' => 'Bottled Water', 'code' => 'WAT', 'price' => 500, 'taxable' => false]);

    return compact('rice', 'drinks', 'jollof', 'regular', 'large', 'protein', 'chicken', 'beef', 'extras', 'plantain', 'coke', 'water');
}
