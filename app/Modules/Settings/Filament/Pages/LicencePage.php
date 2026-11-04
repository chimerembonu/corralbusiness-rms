<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Support\Licence\LicenceException;
use App\Support\Licence\LicenceService;
use App\Support\Licence\LicenceStatus;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Back office → Settings → Licence (Complete Edition E1.2): the plan, branches, end date and what the plan
 * includes. Owner and managers can look; only the owner can enter a new licence code. Stays open in
 * reports-only mode, so a renewed code can always be entered.
 */
class LicencePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'licence';

    protected string $view = 'settings::filament.licence';

    public string $code = '';

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
        return __('settings::settings.licence.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.licence.title');
    }

    public function canInstall(): bool
    {
        return auth()->user()?->hasRole(Role::Owner->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $service = app(LicenceService::class);
        $licence = $service->current();
        $status = $service->status();
        $tiers = config('features.tiers', []);
        $included = [];
        if ($licence) {
            foreach (config('features.features', []) as $key => $definition) {
                if (($tiers[$licence->plan] ?? 0) >= ($tiers[$definition['tier']] ?? PHP_INT_MAX) || in_array($key, $licence->extras, true)) {
                    $included[] = __("settings::settings.features.items.{$key}.name");
                }
            }
        }

        return [
            'licence' => $licence,
            'status' => $status,
            'status_label' => __('settings::settings.licence.status.'.$status->value),
            'status_ok' => $status === LicenceStatus::Active,
            'plan' => $licence ? __('settings::settings.features.tiers.'.$licence->plan) : null,
            'extras' => $licence ? array_map(fn ($k) => __("settings::settings.features.items.{$k}.name"), $licence->extras) : [],
            'included' => $included,
            'branches_used' => $service->branchesInUse(),
            'days_left' => $service->daysLeft(),
            'read_only_from' => $licence ? $service->readOnlyFrom($licence)->translatedFormat('j F Y, H:i') : null,
            'warning' => $service->warning(),
            'restaurant_id' => app(TenantContext::class)->tenantId(),
        ];
    }

    public function install(): void
    {
        abort_unless($this->canInstall(), 403);
        $this->validate(['code' => ['required', 'string', 'max:4000']], [], ['code' => __('settings::settings.licence.code')]);
        try {
            $licence = app(LicenceService::class)->install($this->code, auth()->user());
        } catch (LicenceException $e) {
            Notification::make()->danger()->title(__('settings::settings.licence.not_installed'))->body($e->getMessage())->persistent()->send();

            return;
        }
        $this->code = '';
        Notification::make()->success()->title(__('settings::settings.licence.installed', [
            'plan' => __('settings::settings.features.tiers.'.$licence->plan),
            'date' => $licence->expires->translatedFormat('j F Y'),
        ]))->send();
    }
}
