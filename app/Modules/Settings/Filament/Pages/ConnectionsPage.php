<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Staff\Enums\Role;
use App\Support\Connections\ConnectionException;
use App\Support\Connections\ConnectionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Back office → Settings → Connections (Complete Edition E1): the outside services filled in at
 * installation — payment providers, the online service, SMS, WhatsApp, e-VAT. Owner only: these
 * hold keys that can move money.
 */
class ConnectionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'connections';

    protected string $view = 'settings::filament.connections';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(Role::Owner->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.connections.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.connections.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.connections.help');
    }

    /** @return array<int, array<string, mixed>> */
    public function connections(): array
    {
        $service = app(ConnectionService::class);
        $rows = [];
        foreach ($service->definitions() as $name => $definition) {
            $test = $service->lastTest($name);
            $rows[] = [
                'name' => $name,
                'label' => __("settings::settings.connections.names.{$name}"),
                'about' => __("settings::settings.connections.about.{$name}"),
                'configured' => $service->isConfigured($name),
                'ready' => $service->isReady($name),
                'test' => $test,
                'tested_at' => $test ? Carbon::parse($test['at'])->diffForHumans() : null,
                'details' => $service->masked($name),
            ];
        }

        return $rows;
    }

    public function configureAction(): Action
    {
        return Action::make('configure')
            ->modalHeading(fn (array $arguments) => __('settings::settings.connections.names.'.($arguments['name'] ?? '')))
            ->modalDescription(fn (array $arguments) => __('settings::settings.connections.about.'.($arguments['name'] ?? '')))
            ->fillForm(function (array $arguments) {
                $name = (string) ($arguments['name'] ?? '');
                $service = app(ConnectionService::class);
                $definition = $service->definition($name);
                $values = $service->values($name);

                // Secrets are never sent back to the browser: their boxes start empty ("leave empty to keep").
                return collect($definition['fields'])->mapWithKeys(fn ($spec, $field) => [$field => ($spec['secret'] ?? false) ? null : ($values[$field] ?? null)])->all();
            })
            ->schema(function (array $arguments) {
                $name = (string) ($arguments['name'] ?? '');
                $service = app(ConnectionService::class);
                $configured = $service->isConfigured($name);
                $t = fn (string $k) => __("settings::settings.connections.{$k}");

                return collect($service->definition($name)['fields'])->map(function (array $spec, string $field) use ($t, $configured) {
                    if (($spec['type'] ?? 'text') === 'select') {
                        return Select::make($field)->label($t("fields.{$field}"))->required($spec['required'] ?? false)
                            ->options(collect($spec['options'])->mapWithKeys(fn ($o) => [$o => __("settings::settings.connections.modes.{$o}")])->all());
                    }
                    $input = TextInput::make($field)->label($t("fields.{$field}"))->maxLength($spec['max'] ?? 255);
                    if ($spec['secret'] ?? false) {
                        return $input->password()->revealable()->autocomplete('off')
                            ->required(($spec['required'] ?? false) && ! $configured)
                            ->helperText($configured ? $t('secret_kept') : null);
                    }

                    return $input->required($spec['required'] ?? false)->url(($spec['type'] ?? '') === 'url');
                })->values()->all();
            })
            ->action(function (array $data, array $arguments) {
                abort_unless(self::canAccess(), 403);
                $name = (string) ($arguments['name'] ?? '');
                try {
                    app(ConnectionService::class)->save($name, $data, auth()->user());
                    Notification::make()->success()->title(__('settings::settings.connections.saved', ['connection' => __("settings::settings.connections.names.{$name}")]))->send();
                } catch (ConnectionException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
            });
    }

    public function runTest(string $name): void
    {
        abort_unless(self::canAccess(), 403);
        $result = app(ConnectionService::class)->test($name, auth()->user());
        Notification::make()->{$result->ok ? 'success' : 'warning'}()
            ->title(__("settings::settings.connections.names.{$name}"))->body($result->message)->persistent()->send();
    }

    public function removeAction(): Action
    {
        return Action::make('remove')->color('danger')->requiresConfirmation()
            ->modalDescription(__('settings::settings.connections.remove_confirm'))
            ->action(function (array $arguments) {
                abort_unless(self::canAccess(), 403);
                $name = (string) ($arguments['name'] ?? '');
                app(ConnectionService::class)->remove($name, auth()->user());
                Notification::make()->success()->title(__('settings::settings.connections.removed', ['connection' => __("settings::settings.connections.names.{$name}")]))->send();
            });
    }
}
