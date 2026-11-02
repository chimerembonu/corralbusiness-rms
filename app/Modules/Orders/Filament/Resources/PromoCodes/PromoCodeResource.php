<?php

namespace App\Modules\Orders\Filament\Resources\PromoCodes;

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Modules\Orders\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Modules\Orders\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\MoneyInput;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'code';

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::OrdersDiscount->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('orders::orders.promo.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('orders::orders.promo.promo_code');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders::orders.promo.promo_codes');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $key) => __('orders::orders.promo.fields.'.$key);

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('code')->label($f('code'))->required()->maxLength(30)->unique(ignoreRecord: true)->extraInputAttributes(['style' => 'text-transform: uppercase']),
                TextInput::make('name')->label($f('name'))->required()->maxLength(80),
                Select::make('type')->label($f('type'))->options([DiscountType::Percent->value => $f('percent'), DiscountType::Fixed->value => $f('fixed')])->default('percent')->required()->live(),
                TextInput::make('value')->label(fn (Get $get) => $get('type') === 'fixed' ? $f('value_fixed') : $f('value_percent'))
                    ->numeric()->required()->minValue(0.01)
                    ->afterStateHydrated(fn (TextInput $component, $state, Get $get) => $component->state($state === null ? null : ($get('type') === 'fixed' ? number_format($state / 100, 2, '.', '') : rtrim(rtrim(number_format($state / 100, 2, '.', ''), '0'), '.'))))
                    ->dehydrateStateUsing(fn ($state) => (int) round((float) $state * 100))
                    ->suffix(fn (Get $get) => $get('type') === 'fixed' ? config('rms.currency') : '%'),
                MoneyInput::make('min_order')->label($f('min_order'))->default(0),
                MoneyInput::make('max_discount')->label($f('max_discount'))->visible(fn (Get $get) => $get('type') !== 'fixed'),
                DateTimePicker::make('starts_at')->label($f('starts_at'))->seconds(false),
                DateTimePicker::make('ends_at')->label($f('ends_at'))->seconds(false)->after('starts_at'),
                TextInput::make('max_uses')->label($f('max_uses'))->numeric()->minValue(1),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $key) => __('orders::orders.promo.fields.'.$key);

        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label($f('code'))->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->label($f('name'))->searchable(),
                TextColumn::make('value')->label($f('value'))->formatStateUsing(fn (PromoCode $record) => $record->describe()),
                TextColumn::make('min_order')->label($f('min_order'))->formatStateUsing(fn ($state) => $state ? money($state) : '—'),
                TextColumn::make('ends_at')->label($f('ends_at'))->dateTime('d M Y H:i')->placeholder('—'),
                TextColumn::make('uses_count')->label($f('uses_count'))->formatStateUsing(fn (PromoCode $record) => $record->uses_count.($record->max_uses ? ' / '.$record->max_uses : '')),
                IconColumn::make('is_active')->label($f('is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodes::route('/'),
            'create' => CreatePromoCode::route('/create'),
            'edit' => EditPromoCode::route('/{record}/edit'),
        ];
    }
}
