<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Support\Errors\FriendlyError;
use App\Support\Features\FeatureException;
use App\Support\Features\FeatureService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Back office → Settings → Features (Complete Edition E1): one switch per optional feature, with what
 * still stops it. Owner and managers can look; only the owner can switch.
 */
class FeaturesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'features';

    protected string $view = 'settings::filament.features';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.features.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.features.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.features.help');
    }

    public function canSwitch(): bool
    {
        return auth()->user()?->hasRole(Role::Owner->value) ?? false;
    }

    /** @return array<string, array<int, array<string, mixed>>> group → features */
    public function groups(): array
    {
        $service = app(FeatureService::class);
        $groups = [];
        foreach ($service->definitions() as $key => $definition) {
            $groups[$definition['group']][] = [
                'key' => $key,
                'name' => __("settings::settings.features.items.{$key}.name"),
                'about' => __("settings::settings.features.items.{$key}.about"),
                'tier' => __('settings::settings.features.tiers.'.$definition['tier']),
                'available' => (bool) ($definition['available'] ?? false),
                'stage' => $definition['stage'] ?? '',
                'on' => $service->isOn($key),
                'switched_on' => $service->switchedOn($key),
                'blockers' => $service->blockers($key),
            ];
        }

        return $groups;
    }

    public function toggle(string $key): void
    {
        abort_unless($this->canSwitch(), 403);
        $service = app(FeatureService::class);
        $name = __("settings::settings.features.items.{$key}.name");
        try {
            $on = ! $service->switchedOn($key);
            $service->set($key, $on, auth()->user());
            Notification::make()->success()->title(__($on ? 'settings::settings.features.switched_on' : 'settings::settings.features.switched_off', ['feature' => $name]))->send();
        } catch (FeatureException $e) {
            Notification::make()->warning()->title($name)->body($e->getMessage())->persistent()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->danger()->title(FriendlyError::message($e))->send();
        }
    }
}
