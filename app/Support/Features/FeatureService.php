<?php

namespace App\Support\Features;

use App\Modules\Settings\Models\ModuleSetting;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Licence\LicenceService;
use App\Support\Licence\LicenceStatus;
use App\Support\Tenancy\TenantContext;

/**
 * Complete Edition features (config/features.php). Every feature starts OFF. It is ON only when it is
 * built, the restaurant's licence covers it, every connection it needs is set up and tested, and the
 * owner switched it on. If a needed connection later fails its test, the feature reports OFF again.
 * The switch is kept in module_settings as "feature:<key>".
 */
class FeatureService
{
    public function __construct(private readonly ConnectionService $connections, private readonly TenantContext $context, private readonly LicenceService $licence) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return config('features.features', []);
    }

    public function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw new FeatureException("Unknown feature {$key}");
    }

    /** Use this everywhere a feature changes behaviour: `if (app(FeatureService::class)->isOn('momo_prompt'))`. */
    public function isOn(string $key): bool
    {
        return $this->switchedOn($key) && $this->blockers($key) === [];
    }

    public function switchedOn(string $key): bool
    {
        if ($this->context->tenantId() === null) {
            return false;
        }

        return (bool) ModuleSetting::query()->where('module', self::storageKey($key))->value('enabled');
    }

    /**
     * Why the feature cannot be on right now, in plain words (empty = nothing stops it).
     *
     * @return array<int, string>
     */
    public function blockers(string $key): array
    {
        $definition = $this->definition($key);
        $reasons = [];
        if (! ($definition['available'] ?? false)) {
            $reasons[] = __('settings::settings.features.blockers.not_built', ['stage' => $definition['stage'] ?? '']);
        }
        if (! $this->licensed($key)) {
            $tier = __('settings::settings.features.tiers.'.($definition['tier'] ?? 'starter'));
            $reasons[] = match ($this->licence->status()) {
                LicenceStatus::None => __('settings::settings.features.blockers.no_licence', ['tier' => $tier]),
                LicenceStatus::ReadOnly => __('settings::settings.features.blockers.licence_ended'),
                default => __('settings::settings.features.blockers.licence', ['tier' => $tier]),
            };
        }
        foreach ($definition['needs'] ?? [] as $need) {
            if (! $this->connections->needMet($need)) {
                $reasons[] = __('settings::settings.features.blockers.need', ['need' => __("settings::settings.features.needs.{$need}")]);
            }
        }

        return $reasons;
    }

    /** Switch a feature on or off. Switching on is refused, with the reasons, while anything blocks it. */
    public function set(string $key, bool $on, ?User $by = null): void
    {
        $this->definition($key);
        if ($on && ($reasons = $this->blockers($key)) !== []) {
            throw new FeatureException(implode(' ', $reasons));
        }
        ModuleSetting::query()->updateOrCreate(['module' => self::storageKey($key)], ['enabled' => $on]);
        activity('settings')->event($on ? 'feature_on' : 'feature_off')->causedBy($by)->withProperties(['feature' => $key])->log($on ? 'feature_on' : 'feature_off');
    }

    /** Covered by the restaurant's licence (plan high enough, or sold on top) and the licence is not read-only. */
    public function licensed(string $key): bool
    {
        return $this->licence->allowsFeature($key, $this->definition($key)['tier'] ?? 'starter');
    }

    public static function storageKey(string $key): string
    {
        return 'feature:'.$key;
    }
}
