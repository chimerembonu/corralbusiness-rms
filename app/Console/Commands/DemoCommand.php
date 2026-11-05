<?php

namespace App\Console\Commands;

use App\Modules\Customers\Seeders\CustomersDemoSeeder;
use App\Modules\Inventory\Seeders\IngredientsDemoSeeder;
use App\Modules\Inventory\Seeders\RecipesDemoSeeder;
use App\Modules\Inventory\Seeders\SuppliersDemoSeeder;
use App\Modules\Kitchen\Seeders\StationsDemoSeeder;
use App\Modules\Menu\Seeders\MenuDemoSeeder;
use App\Modules\Orders\Seeders\DiningTablesDemoSeeder;
use App\Modules\Orders\Seeders\PromoCodesDemoSeeder;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Pos\Seeders\TillsDemoSeeder;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\TenantProvisioner;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Seeders\RosterDemoSeeder;
use App\Modules\Staff\Seeders\StaffDemoSeeder;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan rms:demo
 *
 * Loads the demo restaurant (CLAUDE.md §5): tenant + branch, roles, the owner's
 * admin login and the Ghanaian menu. Safe to run again – it updates rather than duplicates.
 * Later steps add tables, tills, stations, staff and ingredients.
 */
class DemoCommand extends Command
{
    protected $signature = 'rms:demo
        {--fresh : Delete the demo restaurant first and rebuild it from scratch}
        {--admin-email=chimere@corralsystems.com : Email for the owner login}
        {--admin-name=Chimere : Name for the owner login}
        {--admin-pin=1234 : POS PIN for the owner login}
        {--password= : Password for the owner login (generated and shown once if omitted)}';

    protected $description = 'Load the demo Ghanaian restaurant: menu, tables, tills, stations, staff, ingredients and owner login';

    public const TENANT_SLUG = 'demo';

    public function handle(TenantProvisioner $provisioner, MenuDemoSeeder $menu): int
    {
        (new RolesAndPermissionsSeeder)->run();
        $this->components->info('Roles and permissions are up to date.');

        if ($this->option('fresh')) {
            Tenant::withTrashed()->where('slug', self::TENANT_SLUG)->get()->each->forceDelete();
            $this->components->warn('Demo restaurant deleted; rebuilding.');
        }

        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if (! $tenant) {
            $branch = $provisioner->provision('Corral Demo Chop Bar', 'Osu Branch', ['slug' => self::TENANT_SLUG]);
            $tenant = $branch->tenant;
            $this->components->info("Created restaurant \"{$tenant->name}\" with branch \"{$branch->name}\" and default Ghana taxes.");
        } else {
            $branch = Branch::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->orderBy('created_at')->firstOrFail();
            $this->components->info("Restaurant \"{$tenant->name}\" already exists; updating it.");
        }

        Tenancy::set($tenant, $branch);

        $this->ensureOwner($tenant, $branch);

        $counts = $menu->run();
        $this->components->info(sprintf(
            'Menu loaded: %d categories, %d items, %d sizes, %d modifier groups, %d options.',
            $counts['categories'], $counts['items'], $counts['variants'], $counts['modifier_groups'], $counts['modifiers']
        ));

        $tables = (new DiningTablesDemoSeeder)->run();
        $tills = (new TillsDemoSeeder)->run();
        $stations = (new StationsDemoSeeder)->run();
        $staff = (new StaffDemoSeeder)->run();
        $ingredients = (new IngredientsDemoSeeder)->run();
        $recipes = (new RecipesDemoSeeder)->run(); // used only once "Recipes & food cost" is switched on
        $promos = (new PromoCodesDemoSeeder)->run();
        $roster = (new RosterDemoSeeder)->run();
        $inventory = (new SuppliersDemoSeeder)->run();
        $customers = (new CustomersDemoSeeder)->run();
        $this->components->info("Tables: {$tables} · Tills: {$tills} · Stations: {$stations} · Staff: {$staff} · Ingredients: {$ingredients} · Recipe lines: {$recipes} · Promo codes: {$promos} · Roster shifts: {$roster['shifts']} · Clock-ins: {$roster['entries']} · Suppliers: {$inventory['suppliers']} · Counted drinks: {$inventory['tracked_items']} · Customers: {$customers}.");

        $settings = app(SettingsService::class);
        $settings->set('receipt.header', 'Akwaaba! TIN: C0000000000 (demo)', tenantWide: true);
        $settings->set('receipt.footer', 'Thank you! Medaase! Please come again.', tenantWide: true);
        // Demo till rules: 10 % service charge on dine-in; everything else uses the defaults (Back office → Till settings).
        if ($settings->get(TillSettings::SERVICE_CHARGE_BP) === null) {
            $settings->set(TillSettings::SERVICE_CHARGE_BP, 1000);
        }
        if ($settings->get(TillSettings::DELIVERY_FEE) === null) {
            $settings->set(TillSettings::DELIVERY_FEE, 1000);
        }
        $branch->forceFill(['address' => '12 Oxford Street, Osu, Accra', 'phone' => '+233 30 000 0000'])->save();

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Staff PINs for the demo</>', '');
        $this->components->twoColumnDetail($this->option('admin-name').' (Owner)', $this->option('admin-pin'));
        foreach (StaffDemoSeeder::staff() as $member) {
            $this->components->twoColumnDetail($member['name'].' ('.ucfirst($member['role']).')', $member['pin']);
        }

        $this->newLine();
        $this->line('  Back office: '.url(config('rms.admin_path')));
        $this->line('  Till (POS):  '.url('pos'));
        $this->line('  Kitchen:     '.url('kitchen'));
        $this->line('  Start here:  '.url('/'));

        return self::SUCCESS;
    }

    private function ensureOwner(Tenant $tenant, Branch $branch): void
    {
        $email = $this->option('admin-email');
        $user = User::query()->withoutGlobalScope(TenantScope::class)->where('email', $email)->first();

        if ($user) {
            if ($this->option('password')) {
                $user->forceFill(['password' => $this->option('password')])->save();
                $this->components->info("Password for {$email} was reset.");
            }
            $user->assignRole(Role::Owner->value);
            $this->components->info("Owner login {$email} already exists (password unchanged).");

            return;
        }

        $password = $this->option('password') ?: Str::password(14, symbols: false);

        $user = new User([
            'name' => $this->option('admin-name'),
            'email' => $email,
            'password' => $password,
            'pin' => $this->option('admin-pin'),
            'is_active' => true,
        ]);
        $user->tenant_id = $tenant->id;
        $user->branch_id = $branch->id;
        $user->email_verified_at = now();
        $user->save();
        $user->assignRole(Role::Owner->value);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Owner login created</>', '');
        $this->components->twoColumnDetail('Email', $email);
        $this->components->twoColumnDetail('Password (shown once, not stored anywhere else)', $password);
        $this->components->twoColumnDetail('POS PIN', $this->option('admin-pin'));
        $this->newLine();
    }
}
