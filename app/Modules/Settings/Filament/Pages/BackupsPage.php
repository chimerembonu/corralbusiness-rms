<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Settings\Services\BackupService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Support\Errors\FriendlyError;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** Back office → Settings → Backups: list, back up now, download, restore. Owner and managers only. */
class BackupsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'backups';

    protected string $view = 'settings::filament.backups';

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.backups.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function getTitle(): string
    {
        return __('settings::settings.backups.title');
    }

    public function backups(): array
    {
        return app(BackupService::class)->list();
    }

    public function nextRun(): string
    {
        return __('settings::settings.backups.nightly', ['time' => '02:30']);
    }

    public function backupNowAction(): Action
    {
        return Action::make('backupNow')->label(__('settings::settings.backups.backup_now'))->icon(Heroicon::Plus)
            ->action(function () {
                try {
                    $path = app(BackupService::class)->create(__('settings::settings.backups.manual_note', ['name' => auth()->user()->name]));
                    Notification::make()->success()->title(__('settings::settings.backups.done', ['file' => basename($path)]))->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(FriendlyError::message($e))->send();
                }
            });
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')->label(__('settings::settings.backups.restore'))->icon(Heroicon::ArrowUturnLeft)->color('danger')
            ->visible(fn () => auth()->user()->hasRole(Role::Owner->value))
            ->modalHeading(fn (array $arguments) => __('settings::settings.backups.restore_heading', ['file' => $arguments['file'] ?? '']))
            ->modalDescription(__('settings::settings.backups.restore_help'))
            ->schema([TextInput::make('confirm')->label(__('settings::settings.backups.type_restore'))->required()->rule('in:RESTORE')])
            ->action(function (array $data, array $arguments) {
                try {
                    $service = app(BackupService::class);
                    $safety = $service->restore($service->resolve($arguments['file']));
                    Notification::make()->success()->title(__('settings::settings.backups.restored', ['file' => $arguments['file'], 'safety' => basename($safety)]))->persistent()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(FriendlyError::message($e))->persistent()->send();
                }
            });
    }

    public function uploadRestoreAction(): Action
    {
        return Action::make('uploadRestore')->label(__('settings::settings.backups.upload'))->icon(Heroicon::ArrowUpTray)->color('gray')
            ->visible(fn () => auth()->user()->hasRole(Role::Owner->value))
            ->modalDescription(__('settings::settings.backups.restore_help'))
            ->schema([
                FileUpload::make('file')->label(__('settings::settings.backups.file'))->disk('local')->directory('backup-uploads')->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->required(),
                TextInput::make('password')->label(__('settings::settings.backups.password'))->password()->revealable()->helperText(__('settings::settings.backups.password_help')),
                TextInput::make('confirm')->label(__('settings::settings.backups.type_restore'))->required()->rule('in:RESTORE'),
            ])
            ->action(function (array $data) {
                try {
                    $path = storage_path('app/private/'.$data['file']);
                    if (! is_file($path)) {
                        $path = storage_path('app/'.$data['file']);
                    }
                    $safety = app(BackupService::class)->restore($path, $data['password'] ?? null);
                    @unlink($path);
                    Notification::make()->success()->title(__('settings::settings.backups.restored', ['file' => basename($data['file']), 'safety' => basename($safety)]))->persistent()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(FriendlyError::message($e))->persistent()->send();
                }
            });
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')->label(__('filament-actions::delete.single.label'))->icon(Heroicon::Trash)->color('gray')->requiresConfirmation()
            ->visible(fn () => auth()->user()->hasRole(Role::Owner->value)) // security review H2: owner only, like restore
            ->action(function (array $arguments) {
                abort_unless(auth()->user()->hasRole(Role::Owner->value), 403);
                @unlink(app(BackupService::class)->resolve((string) ($arguments['file'] ?? '')));
                Notification::make()->success()->title(__('settings::settings.backups.deleted'))->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->backupNowAction(), $this->uploadRestoreAction()];
    }
}
