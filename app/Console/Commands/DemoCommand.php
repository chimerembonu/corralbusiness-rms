<?php

namespace App\Console\Commands;

use App\Modules\Menu\Seeders\MenuDemoSeeder;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Settings\Services\TenantProvisioner;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
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

    protected $description = 'Load the demo Ghanaian restaurant with menu and owner login';

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

        $this->newLine();
        $this->line('  Back office: '.url(config('rms.admin_path')));

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
