<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Settings\Services\Import\Importers;
use App\Modules\Settings\Services\Import\ImportResult;
use App\Modules\Settings\Services\Import\ImportTemplates;
use App\Modules\Staff\Enums\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/** Back office → Settings → Import data: download a template, upload it filled in, preview, import. */
class ImportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = 'import';

    protected string $view = 'settings::filament.import';

    /** @var array<string, mixed> */
    public ?array $data = ['type' => 'menu', 'file' => null];

    /** @var array<int, array{row: int, action: string, label: string, message: string}>|null */
    public ?array $preview = null;

    public ?string $summary = null;

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.import.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function getTitle(): string
    {
        return __('settings::settings.import.title');
    }

    public function mount(): void
    {
        $this->form->fill(['type' => 'menu']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label(__('settings::settings.import.what'))->options(array_combine(ImportTemplates::TYPES, array_map(fn ($t) => __('settings::settings.import.types.'.$t), ImportTemplates::TYPES)))->required()->native(false)->live()
                ->afterStateUpdated(fn () => $this->reset('preview', 'summary')),
            FileUpload::make('file')->label(__('settings::settings.import.file'))->disk('local')->directory('imports')->preserveFilenames()
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'text/csv', 'text/plain', 'application/csv'])
                ->helperText(__('settings::settings.import.file_help'))->required(),
        ])->statePath('data');
    }

    private function uploadedPath(): string
    {
        $file = $this->form->getState()['file'] ?? null;
        if ($path = $this->storedUploadPath(is_string($file) ? $file : null)) {
            return $path;
        }

        throw new RuntimeException(__('settings::settings.import.errors.file_missing'));
    }

    /** Where a saved upload sits on disk (the "local" disk is private); null when there is none. */
    private function storedUploadPath(?string $file): ?string
    {
        if (! $file) {
            return null;
        }
        foreach ([storage_path('app/private/'.$file), storage_path('app/'.$file)] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function previewFile(): void
    {
        try {
            $result = Importers::preview($this->form->getState()['type'], $this->uploadedPath());
            $this->show($result);
        } catch (RuntimeException|\Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function importFile(): void
    {
        $path = null;

        try {
            $state = $this->form->getState(); // validates and saves the upload under imports/
            $path = $this->storedUploadPath(is_string($state['file'] ?? null) ? $state['file'] : null)
                ?? throw new RuntimeException(__('settings::settings.import.errors.file_missing'));
            $result = Importers::import($state['type'], $path, auth()->user());
            $this->show($result);
            Notification::make()->success()->title(__('settings::settings.import.done', ['summary' => $result->summary()]))->persistent()->send();
        } catch (RuntimeException|\Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        } finally {
            // The spreadsheet (which may hold every staff PIN) is not kept on the server once it has been read (re-review).
            if ($path && is_file($path)) {
                @unlink($path);
            }
            $this->data['file'] = null;
        }
    }

    private function show(ImportResult $result): void
    {
        $this->preview = $result->rows;
        $this->summary = ($result->dryRun ? __('settings::settings.import.preview_of') : __('settings::settings.import.imported')).' '.$result->summary();
    }

    public function templateAction(): Action
    {
        return Action::make('template')->label(__('settings::settings.import.template'))->icon(Heroicon::ArrowDownTray)->color('gray')
            ->action(fn () => Excel::download(ImportTemplates::export($this->data['type'] ?? 'menu'), ImportTemplates::fileName($this->data['type'] ?? 'menu')));
    }

    protected function getHeaderActions(): array
    {
        return [$this->templateAction()];
    }
}
