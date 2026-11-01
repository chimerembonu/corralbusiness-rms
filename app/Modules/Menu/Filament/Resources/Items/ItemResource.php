<?php

namespace App\Modules\Menu\Filament\Resources\Items;

use App\Modules\Menu\Enums\Allergen;
use App\Modules\Menu\Enums\ItemType;
use App\Modules\Menu\Filament\Resources\Items\Pages\CreateItem;
use App\Modules\Menu\Filament\Resources\Items\Pages\EditItem;
use App\Modules\Menu\Filament\Resources\Items\Pages\ListItems;
use App\Modules\Menu\Filament\Resources\Items\RelationManagers\BranchPricesRelationManager;
use App\Modules\Menu\Filament\Resources\Items\RelationManagers\ComboItemsRelationManager;
use App\Modules\Menu\Filament\Resources\Items\RelationManagers\VariantsRelationManager;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Settings\Models\TaxComponent;
use App\Support\Filament\AvailabilityFields;
use App\Support\Filament\MoneyInput;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('menu::menu.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('menu::menu.item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('menu::menu.items');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('menu::menu.fields.name'))->required()->maxLength(120),
                Select::make('category_id')->label(__('menu::menu.fields.category'))
                    ->options(fn () => Category::query()->ordered()->pluck('name', 'id'))
                    ->required()->searchable()->preload(),
                Select::make('type')->label(__('menu::menu.fields.type'))
                    ->options(collect(ItemType::cases())->mapWithKeys(fn (ItemType $t) => [$t->value => $t->label()]))
                    ->default(ItemType::Standard->value)->required()->live(),
                TextInput::make('code')->label(__('menu::menu.fields.code'))->helperText(__('menu::menu.hints.code'))->maxLength(30),
                MoneyInput::make('price')->label(__('menu::menu.fields.price'))->required()
                    ->helperText(__('menu::menu.hints.price_with_variants')),
                TextInput::make('sort_order')->label(__('menu::menu.fields.sort_order'))->numeric()->default(0),
                Textarea::make('description')->label(__('menu::menu.fields.description'))->rows(2)->maxLength(500)->columnSpanFull(),
                FileUpload::make('image_path')->label(__('menu::menu.fields.image'))->image()->disk('public')->directory('menu/items')->columnSpanFull(),
            ]),
            Section::make(__('menu::menu.fields.tags'))->columns(2)->schema([
                CheckboxList::make('allergens')->label(__('menu::menu.fields.allergens'))->options(Allergen::options())->columns(3)->columnSpanFull(),
                TagsInput::make('tags')->label(__('menu::menu.fields.tags'))->helperText(__('menu::menu.hints.tags'))->columnSpanFull(),
            ]),
            Section::make(__('menu::menu.fields.modifier_groups'))->schema([
                Select::make('modifierGroups')->label(__('menu::menu.fields.modifier_groups'))
                    ->relationship('modifierGroups', 'name')->multiple()->preload()->searchable(),
            ]),
            Section::make(__('menu::menu.fields.taxable'))->columns(3)->schema([
                Toggle::make('taxable')->label(__('menu::menu.fields.taxable'))->default(true)->inline(false)->live(),
                Select::make('tax_exempt_codes')->label(__('menu::menu.fields.tax_exempt_codes'))->multiple()
                    ->options(fn () => TaxComponent::query()->active()->pluck('name', 'code'))
                    ->visible(fn (Get $get) => (bool) $get('taxable'))->columnSpan(2),
                Toggle::make('is_sold_out')->label(__('menu::menu.fields.is_sold_out'))->inline(false),
                Toggle::make('track_stock')->label(__('menu::menu.fields.track_stock'))->inline(false)->live()->helperText(__('inventory::inventory.item_stock.help')),
                Toggle::make('is_active')->label(__('menu::menu.fields.is_active'))->default(true)->inline(false),
                TextInput::make('stock_qty')->label(__('inventory::inventory.item_stock.stock_qty'))->numeric()->default(0)->step('1')->visible(fn (Get $get) => (bool) $get('track_stock')),
                TextInput::make('stock_reorder_level')->label(__('inventory::inventory.item_stock.stock_reorder_level'))->numeric()->default(0)->step('1')->visible(fn (Get $get) => (bool) $get('track_stock')),
            ]),
            AvailabilityFields::make()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'variants']))
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->circular(),
                TextColumn::make('name')->label(__('menu::menu.fields.name'))->searchable()->sortable()
                    ->description(fn (Item $record) => $record->code),
                TextColumn::make('category.name')->label(__('menu::menu.fields.category'))->sortable(),
                TextColumn::make('type')->label(__('menu::menu.fields.type'))->badge()
                    ->formatStateUsing(fn (ItemType $state) => $state->label())
                    ->color(fn (ItemType $state) => $state === ItemType::Combo ? 'info' : 'gray'),
                TextColumn::make('price')->label(__('menu::menu.fields.price'))
                    ->formatStateUsing(fn (Item $record) => $record->hasVariants()
                        ? $record->variants->map(fn ($v) => $v->name.' '.$v->price->format(false))->implode(' · ')
                        : MoneyInput::format($record->price)),
                IconColumn::make('is_sold_out')->label(__('menu::menu.fields.is_sold_out'))->boolean()
                    ->trueIcon(Heroicon::OutlinedNoSymbol)->falseIcon(Heroicon::OutlinedCheckCircle)
                    ->trueColor('danger')->falseColor('success'),
                IconColumn::make('is_active')->label(__('menu::menu.fields.is_active'))->boolean()->toggleable(),
                TextColumn::make('tags')->label(__('menu::menu.fields.tags'))->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')->label(__('menu::menu.fields.category'))
                    ->options(fn () => Category::query()->ordered()->pluck('name', 'id')),
                SelectFilter::make('type')->label(__('menu::menu.fields.type'))
                    ->options(collect(ItemType::cases())->mapWithKeys(fn (ItemType $t) => [$t->value => $t->label()])),
                TernaryFilter::make('is_sold_out')->label(__('menu::menu.fields.is_sold_out')),
                TernaryFilter::make('is_active')->label(__('menu::menu.fields.is_active')),
            ])
            ->recordActions([
                Action::make('toggleSoldOut')
                    ->label(fn (Item $record) => $record->is_sold_out ? __('menu::menu.actions.mark_available') : __('menu::menu.actions.mark_sold_out'))
                    ->icon(fn (Item $record) => $record->is_sold_out ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
                    ->color(fn (Item $record) => $record->is_sold_out ? 'success' : 'danger')
                    ->action(fn (Item $record) => app(MenuService::class)->setSoldOut($record, ! $record->is_sold_out, auth()->id())),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markSoldOut')->label(__('menu::menu.actions.mark_sold_out'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->action(fn (Collection $records) => $records->each(fn (Item $i) => app(MenuService::class)->setSoldOut($i, true, auth()->id())))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markAvailable')->label(__('menu::menu.actions.mark_available'))->icon(Heroicon::OutlinedCheckCircle)->color('success')
                        ->action(fn (Collection $records) => $records->each(fn (Item $i) => app(MenuService::class)->setSoldOut($i, false, auth()->id())))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
            ComboItemsRelationManager::class,
            BranchPricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItems::route('/'),
            'create' => CreateItem::route('/create'),
            'edit' => EditItem::route('/{record}/edit'),
        ];
    }
}
