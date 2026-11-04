<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Staff\Enums\Permission;
use App\Support\Audit\Activity;
use App\Support\Audit\SecurityLog;
use App\Support\Tenancy\Tenancy;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Back office → Settings → Security log: failed sign-ins, wrong PINs, lockouts and refused approvals
 * (compliance assessment, 4 Nov 2026). Read-only. Owner and managers.
 */
class SecurityLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = 'security-log';

    protected string $view = 'settings::filament.security-log';

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
        return __('settings::settings.security_log.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.security_log.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.security_log.help');
    }

    public function table(Table $table): Table
    {
        $events = collect(['pin_failed', 'pin_locked_out', 'pin_inactive_user', 'approval_refused', 'login_failed', 'login_throttled'])
            ->mapWithKeys(fn ($e) => [$e => __("settings::settings.security_log.events.{$e}")])->all();

        return $table
            ->query(Activity::query()->where('log_name', SecurityLog::LOG)->where('tenant_id', Tenancy::tenantId())->with('causer'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('settings::settings.security_log.when'))->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('event')->label(__('settings::settings.security_log.what'))->badge()
                    ->formatStateUsing(fn (?string $state) => $events[$state] ?? $state)
                    ->color(fn (?string $state) => in_array($state, ['pin_locked_out', 'pin_inactive_user', 'login_throttled'], true) ? 'danger' : 'warning'),
                TextColumn::make('causer.name')->label(__('settings::settings.security_log.who'))->placeholder('—'),
                TextColumn::make('properties.email')->label(__('settings::settings.security_log.email'))->placeholder('—'),
                TextColumn::make('properties.till')->label(__('settings::settings.security_log.where'))->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? preg_replace('/^till:/', '', $state) : null),
                TextColumn::make('properties.needs')->label(__('settings::settings.security_log.needed'))->placeholder('—'),
                TextColumn::make('properties.ip')->label(__('settings::settings.security_log.ip'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event')->label(__('settings::settings.security_log.what'))->options($events),
            ])
            ->paginated([25, 50, 100]);
    }
}
