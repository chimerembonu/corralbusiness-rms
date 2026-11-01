<?php

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\ModuleSetting;
use App\Modules\Settings\Models\Setting;
use App\Support\Modules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Read/write settings for the current tenant and branch.
 * A branch-level value overrides the tenant-level value for the same key.
 */
class SettingsService
{
    public function __construct(private readonly TenantContext $context) {}

    public function get(string $key, mixed $default = null, Branch|string|null $branch = null): mixed
    {
        $branchId = $this->branchId($branch);
        $all = $this->all($branchId);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** Set a tenant-wide value (branch null) or a branch override. */
    public function set(string $key, mixed $value, Branch|string|null $branch = null, bool $tenantWide = false): Setting
    {
        $branchId = $tenantWide ? null : $this->branchId($branch);

        $setting = Setting::query()
            ->where('key', $key)
            ->where('branch_id', $branchId)
            ->first() ?? new Setting(['key' => $key, 'branch_id' => $branchId]);

        $setting->value = ['v' => $value];
        $setting->save();

        $this->forgetCache($branchId);

        return $setting;
    }

    public function forget(string $key, Branch|string|null $branch = null, bool $tenantWide = false): void
    {
        $branchId = $tenantWide ? null : $this->branchId($branch);

        Setting::query()->where('key', $key)->where('branch_id', $branchId)->get()->each->delete();

        $this->forgetCache($branchId);
    }

    /** Per-request copy (shared by every instance), so a till render reads the cache table once instead of on every setting. */
    private static array $memo = [];

    /** @return array<string, mixed> merged tenant + branch values */
    public function all(?string $branchId = null): array
    {
        $tenantId = $this->context->tenantId();

        if ($tenantId === null) {
            return [];
        }

        return self::$memo[$this->cacheKey($tenantId, $branchId)] ??= Cache::remember($this->cacheKey($tenantId, $branchId), now()->addHour(), function () use ($branchId) {
            $rows = Setting::query()
                ->forBranchOrTenant($branchId)
                ->orderByRaw('branch_id is null desc') // tenant rows first so branch rows overwrite
                ->get();

            $values = [];
            foreach ($rows as $row) {
                $values[$row->key] = $row->value['v'] ?? null;
            }

            return $values;
        });
    }

    /** Is a module switched on for the current tenant? Core modules are always on. */
    public function moduleEnabled(string $module): bool
    {
        if (! Modules::isEnabled($module)) {
            return false;
        }

        if (Modules::isCore($module) || $this->context->tenantId() === null) {
            return true;
        }

        $row = ModuleSetting::query()->where('module', $module)->first();

        return $row ? $row->enabled : (bool) config("modules.modules.{$module}.enabled", false);
    }

    public function setModuleEnabled(string $module, bool $enabled): ModuleSetting
    {
        return ModuleSetting::query()->updateOrCreate(['module' => $module], ['enabled' => $enabled]);
    }

    public function forgetCache(?string $branchId = null): void
    {
        self::$memo = [];
        $tenantId = $this->context->tenantId();
        if ($tenantId === null) {
            return;
        }

        Cache::forget($this->cacheKey($tenantId, $branchId));
        Cache::forget($this->cacheKey($tenantId, null));
        if ($this->context->branchId()) {
            Cache::forget($this->cacheKey($tenantId, $this->context->branchId()));
        }
    }

    private function branchId(Branch|string|null $branch): ?string
    {
        return $branch instanceof Branch ? $branch->getKey() : ($branch ?? $this->context->branchId());
    }

    private function cacheKey(string $tenantId, ?string $branchId): string
    {
        return "rms:settings:{$tenantId}:".($branchId ?? 'tenant');
    }
}
