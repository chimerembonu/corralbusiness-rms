<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Back office → Receipt design: logo, header lines, TIN, footer. Applies to paper and browser receipts. */
class ReceiptSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 91;

    protected string $view = 'settings::filament.receipt-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
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
        return __('settings::settings.receipt.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.receipt.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.receipt.subheading', ['branch' => Tenancy::branch()?->name]);
    }

    public function mount(SettingsService $settings): void
    {
        $this->form->fill([
            'logo_path' => $settings->get('receipt.logo_path'),
            'header' => $settings->get('receipt.header'),
            'tax_number' => $settings->get('receipt.tax_number'),
            'footer' => $settings->get('receipt.footer'),
            'show_server' => (bool) $settings->get('receipt.show_server', true),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __('settings::settings.receipt.'.$key);

        return $schema->components([
            Section::make($t('top'))->columns(2)->schema([
                FileUpload::make('logo_path')->label($t('logo'))->image()->disk('public')->directory('receipts')->maxSize(512)->helperText($t('logo_help'))->columnSpanFull(),
                TextInput::make('tax_number')->label($t('tax_number'))->maxLength(40)->helperText($t('tax_number_help')),
                Toggle::make('show_server')->label($t('show_server'))->inline(false),
                Textarea::make('header')->label($t('header'))->rows(3)->helperText($t('header_help'))->columnSpanFull(),
            ]),
            Section::make($t('bottom'))->schema([
                Textarea::make('footer')->label($t('footer'))->rows(2)->helperText($t('footer_help')),
            ]),
        ])->statePath('data');
    }

    public function save(SettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->set('receipt.logo_path', $data['logo_path'] ?: null);
        $settings->set('receipt.header', filled($data['header']) ? trim($data['header']) : null);
        $settings->set('receipt.tax_number', filled($data['tax_number']) ? trim($data['tax_number']) : null);
        $settings->set('receipt.footer', filled($data['footer']) ? trim($data['footer']) : null);
        $settings->set('receipt.show_server', (bool) ($data['show_server'] ?? true));

        Notification::make()->success()->title(__('settings::settings.receipt.saved'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label(__('settings::settings.receipt.preview'))->icon(Heroicon::OutlinedEye)->color('gray')->url(route('receipt.preview'), shouldOpenInNewTab: true),
            Action::make('save')->label(__('settings::settings.receipt.save'))->action('save'),
        ];
    }
}
