<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Services\SettingsService;
use App\Support\Filament\MoneyInput;
use App\Support\Tenancy\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Back office → Till settings: service charge, discount rules, reasons, tips, courses (per branch). */
class TillSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 90;

    protected string $view = 'settings::filament.till-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.till.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.till.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.till.subheading', ['branch' => Tenancy::branch()?->name]);
    }

    public function mount(): void
    {
        $settings = app(TillSettings::class);

        $this->form->fill([
            'service_charge_percent' => $settings->serviceChargeBp() / 100,
            'service_charge_types' => $settings->serviceChargeOrderTypes(),
            'discount_threshold_percent' => $settings->discountThresholdBp() / 100,
            'discount_reasons' => $settings->discountReasons(),
            'void_reasons' => $settings->voidReasons(),
            'tips_enabled' => $settings->tipsEnabled(),
            'courses' => $settings->courses(),
            'delivery_fee' => $settings->deliveryFee(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __('settings::settings.till.'.$key);

        return $schema->components([
            Section::make($t('service_charge'))->columns(2)->schema([
                TextInput::make('service_charge_percent')->label($t('service_charge_percent'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->helperText($t('service_charge_help')),
                CheckboxList::make('service_charge_types')->label($t('service_charge_types'))
                    ->options(array_combine(array_map(fn (OrderType $o) => $o->value, OrderType::cases()), array_map(fn (OrderType $o) => $o->label(), OrderType::cases()))),
            ]),
            Section::make($t('discounts'))->columns(2)->schema([
                TextInput::make('discount_threshold_percent')->label($t('discount_threshold'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->helperText($t('discount_threshold_help')),
                TagsInput::make('discount_reasons')->label($t('discount_reasons'))->placeholder($t('add_reason')),
                TagsInput::make('void_reasons')->label($t('void_reasons'))->placeholder($t('add_reason')),
            ]),
            Section::make($t('other'))->columns(2)->schema([
                Toggle::make('tips_enabled')->label($t('tips_enabled'))->inline(false),
                TagsInput::make('courses')->label($t('courses'))->helperText($t('courses_help')),
                MoneyInput::make('delivery_fee')->label($t('delivery_fee'))->helperText($t('delivery_fee_help')),
            ]),
        ])->statePath('data');
    }

    public function save(SettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->set(TillSettings::SERVICE_CHARGE_BP, (int) round(((float) ($data['service_charge_percent'] ?? 0)) * 100));
        $settings->set(TillSettings::SERVICE_CHARGE_TYPES, array_values($data['service_charge_types'] ?? []));
        $settings->set(TillSettings::DISCOUNT_THRESHOLD_BP, (int) round(((float) ($data['discount_threshold_percent'] ?? 0)) * 100));
        $settings->set(TillSettings::DISCOUNT_REASONS, array_values($data['discount_reasons'] ?? []));
        $settings->set(TillSettings::VOID_REASONS, array_values($data['void_reasons'] ?? []));
        $settings->set(TillSettings::TIPS_ENABLED, (bool) ($data['tips_enabled'] ?? false));
        $settings->set(TillSettings::COURSES, array_values($data['courses'] ?? []));
        $settings->set(TillSettings::DELIVERY_FEE, (int) ($data['delivery_fee'] ?? 0));

        Notification::make()->success()->title(__('settings::settings.till.saved'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label(__('settings::settings.till.save'))->action('save')];
    }
}
