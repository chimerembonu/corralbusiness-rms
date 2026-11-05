<?php

namespace App\Modules\Settings\Services;

use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\TaxComponent;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Settings\Services\Import\Importers;
use App\Modules\Settings\Services\Import\ImportResult;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Errors\UserFacingException;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * First-run setup of a store server (Complete Edition E1.3): used by the browser wizard (/setup) and by
 * `php artisan rms:setup`. Creates the restaurant, its first branch, the owner, taxes, receipt and tills
 * in one go, and only while the server has no restaurant yet.
 */
class SetupService
{
    private const DONE_CACHE = 'rms:setup:done';

    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly SettingsService $settings,
        private readonly TaxService $taxes,
    ) {}

    /** True while no restaurant exists on this server (a fresh install). */
    public function isOpen(): bool
    {
        if (Cache::get(self::DONE_CACHE)) {
            return false;
        }
        $open = ! Tenant::query()->withTrashed()->exists();
        if (! $open) {
            Cache::forever(self::DONE_CACHE, true);
        }

        return $open;
    }

    /** The setup code the installer printed, if one is required (config rms.setup.code). */
    public function codeRequired(): bool
    {
        return (string) config('rms.setup.code') !== '';
    }

    public function codeMatches(string $typed): bool
    {
        return ! $this->codeRequired() || hash_equals((string) config('rms.setup.code'), preg_replace('/\s+/', '', $typed) ?? '');
    }

    /**
     * Creates everything. $data keys:
     *  restaurant, branch, address?, phone?, tin?, owner_name, email, password, pin,
     *  taxes? [code => basis points], prices_include_tax?, receipt_header?, receipt_footer?, show_server?,
     *  tills? [names]
     *
     * @return array{owner: User, branch: Branch}
     */
    public function complete(array $data): array
    {
        $lock = Cache::lock('rms:setup', 30);
        if (! $lock->get()) {
            throw new UserFacingException(__('settings::settings.setup.errors.busy'));
        }
        try {
            if (! $this->isOpen()) {
                throw new UserFacingException(__('settings::settings.setup.errors.already_done'));
            }
            PinHasher::validate((string) $data['pin']);
            if (User::query()->withoutGlobalScope(TenantScope::class)->where('email', $data['email'])->exists()) {
                throw new UserFacingException(__('settings::settings.setup.errors.email_taken', ['email' => $data['email']]));
            }

            (new RolesAndPermissionsSeeder)->run();

            $result = DB::transaction(function () use ($data) {
                $branch = $this->provisioner->provision($data['restaurant'], $data['branch']);
                $branch->forceFill(array_filter(['address' => $data['address'] ?? null, 'phone' => $data['phone'] ?? null]))->save();

                // No branch in context: the owner and the tax, receipt settings belong to the whole restaurant.
                return Tenancy::runAs($branch->tenant, null, function () use ($branch, $data) {
                    $owner = new User(['name' => $data['owner_name'], 'email' => $data['email'], 'password' => $data['password'], 'pin' => $data['pin'], 'is_active' => true]);
                    $owner->tenant_id = $branch->tenant_id; // no branch: the owner belongs to the whole restaurant
                    $owner->save();
                    $owner->assignRole(Role::Owner->value);

                    foreach ($data['taxes'] ?? [] as $code => $basisPoints) {
                        TaxComponent::query()->whereNull('branch_id')->where('code', $code)->update(['rate_basis_points' => (int) $basisPoints]);
                    }
                    if (array_key_exists('prices_include_tax', $data)) {
                        $this->taxes->setPricesIncludeTax((bool) $data['prices_include_tax']);
                    }
                    $this->taxes->forgetCache();

                    $receipt = [
                        'receipt.tax_number' => $data['tin'] ?? null,
                        'receipt.header' => $data['receipt_header'] ?? null,
                        'receipt.footer' => $data['receipt_footer'] ?? null,
                        'receipt.show_server' => (bool) ($data['show_server'] ?? true),
                    ];
                    foreach ($receipt as $key => $value) {
                        $this->settings->set($key, is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), tenantWide: true);
                    }

                    foreach (array_values($data['tills'] ?? []) as $i => $name) {
                        Till::create(['branch_id' => $branch->id, 'name' => trim($name), 'code' => 'T'.($i + 1)]);
                    }

                    $this->settings->set('setup.completed_at', now()->toIso8601String(), tenantWide: true);
                    activity('settings')->event('setup_completed')->causedBy($owner)->performedOn($branch->tenant)
                        ->withProperties(['tills' => count($data['tills'] ?? []), 'prices_include_tax' => $data['prices_include_tax'] ?? null])
                        ->log('setup_completed');

                    return ['owner' => $owner, 'branch' => $branch];
                });
            });

            Cache::forever(self::DONE_CACHE, true);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Imports menu / staff spreadsheets for the new restaurant. Rows with mistakes are skipped and listed;
     * the rest are imported (they can be fixed later in Settings → Import data).
     *
     * @param  array<string, string>  $files  type (menu|staff) => file path
     * @return array<string, ImportResult>
     */
    public function import(Branch $branch, User $owner, array $files): array
    {
        return Tenancy::runAs($branch->tenant, $branch, function () use ($files, $owner) {
            $results = [];
            foreach (['menu', 'staff'] as $type) {
                if (isset($files[$type])) {
                    $results[$type] = Importers::import($type, $files[$type], $owner);
                }
            }

            return $results;
        });
    }
}
