<?php

namespace App\Modules\Inventory\Filament\Resources\Ingredients;

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\BranchResource;
use App\Support\Filament\MoneyInput;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientResource extends BranchResource
{
    protected static ?string $model = Ingredient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.ingredient');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.ingredients');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAny([Permission::InventoryManage->value, Permission::InventoryCount->value]) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $low = Ingredient::query()->forBranch()->active()->lowStock()->count();

        return $low > 0 ? (string) $low : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return array<string, string> */
    public static function categoryOptions(): array
    {
        $used = Ingredient::query()->forBranch()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
        $defaults = ['Dry goods', 'Meat & fish', 'Vegetables & fruit', 'Drinks', 'Dairy & eggs', 'Oils & spices', 'Packaging', 'Cleaning'];
        $all = array_values(array_unique(array_merge($defaults, $used)));

        return array_combine($all, $all);
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('inventory::inventory.fields.'.$k);

        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label($f('name'))->required()->maxLength(100)->columnSpan(2),
                TextInput::make('code')->label($f('code'))->maxLength(30),
                Select::make('category')->label($f('category'))->options(self::categoryOptions())->searchable()->helperText($f('category_help')),
                Select::make('unit')->label($f('unit'))->options(Unit::options())->default(Unit::Kilogram->value)->required(),
                Select::make('supplier_id')->label($f('supplier'))->options(fn () => Supplier::query()->active()->orderBy('name')->pluck('name', 'id')->all())->searchable()->nullable(),
                TextInput::make('pack_name')->label($f('pack_name'))->maxLength(30),
                TextInput::make('pack_quantity')->label($f('pack_quantity'))->numeric()->step('0.001'),
                MoneyInput::make('cost_per_unit')->label($f('cost_per_unit'))->default('0.00'),
                TextInput::make('on_hand')->label($f('on_hand'))->numeric()->default(0)->step('0.001')->helperText($f('on_hand_help'))->disabledOn('edit'),
                TextInput::make('reorder_level')->label($f('reorder_level'))->numeric()->default(0)->step('0.001'),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('inventory::inventory.fields.'.$k);

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable()->description(fn (Ingredient $r) => trim(($r->code ?? '').' '.($r->packLabel() ?? ''))),
                TextColumn::make('category')->label($f('category'))->sortable()->placeholder('—')->toggleable(),
                TextColumn::make('on_hand')->label($f('on_hand'))->formatStateUsing(fn (Ingredient $r) => StockService::qty((float) $r->on_hand).' '.$r->unit->short())->sortable()->alignRight()
                    ->color(fn (Ingredient $r) => $r->isLow() ? 'danger' : null)->weight(fn (Ingredient $r) => $r->isLow() ? 'bold' : null),
                TextColumn::make('reorder_level')->label($f('reorder_level'))->formatStateUsing(fn (Ingredient $r) => StockService::qty((float) $r->reorder_level))->alignRight()->toggleable(),
                TextColumn::make('cost_per_unit')->label($f('cost_per_unit'))->formatStateUsing(fn ($state) => MoneyInput::format($state))->alignRight(),
                TextColumn::make('value')->label($f('value'))->state(fn (Ingredient $r) => money($r->stockValue()))->alignRight()->toggleable(),
                TextColumn::make('supplier.name')->label($f('supplier'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label($f('is_active'))->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('low')->label($f('low'))->query(fn (Builder $q) => $q->lowStock()),
                SelectFilter::make('category')->label($f('category'))->options(fn () => self::categoryOptions()),
            ])
            ->recordActions([
                self::adjustAction(),
                self::wasteAction(),
                Action::make('movements')->label(__('inventory::inventory.actions.movements'))->icon(Heroicon::ListBullet)->color('gray')
                    ->url(fn (Ingredient $r) => StockMovementResource::getUrl('index', ['tableFilters' => ['ingredient_id' => ['value' => $r->id]]])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('inventory::inventory.actions.adjust'))
            ->icon(Heroicon::AdjustmentsHorizontal)
            ->visible(fn () => auth()->user()->can(Permission::InventoryManage->value))
            ->schema([
                TextInput::make('new_level')->label(__('inventory::inventory.actions.new_level'))->numeric()->required()->minValue(0)->step('0.001')
                    ->default(fn (Ingredient $record) => (float) $record->on_hand)->suffix(fn (Ingredient $record) => $record->unit->short())->helperText(__('inventory::inventory.actions.adjust_help')),
                Select::make('reason')->label(__('inventory::inventory.actions.reason'))->required()
                    ->options(fn () => array_combine(__('inventory::inventory.reasons.adjust_presets'), __('inventory::inventory.reasons.adjust_presets')))->searchable()->native(false),
            ])
            ->action(function (Ingredient $record, array $data) {
                try {
                    app(StockService::class)->setLevel($record, (float) $data['new_level'], auth()->user(), $data['reason']);
                    Notification::make()->success()->title(__('inventory::inventory.actions.adjusted', ['name' => $record->name, 'qty' => StockService::qty((float) $record->fresh()->on_hand), 'unit' => $record->unit->short()]))->send();
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function wasteAction(): Action
    {
        return Action::make('waste')
            ->label(__('inventory::inventory.actions.waste'))
            ->icon(Heroicon::Trash)
            ->color('warning')
            ->visible(fn () => auth()->user()->canAny([Permission::InventoryManage->value, Permission::InventoryCount->value]))
            ->schema([
                TextInput::make('quantity')->label(__('inventory::inventory.actions.quantity'))->numeric()->required()->minValue(0.001)->step('0.001')->suffix(fn (Ingredient $record) => $record->unit->short()),
                Select::make('reason')->label(__('inventory::inventory.actions.reason'))->required()
                    ->options(fn () => array_combine(__('inventory::inventory.reasons.waste_presets'), __('inventory::inventory.reasons.waste_presets')))->searchable()->native(false),
            ])
            ->action(function (Ingredient $record, array $data) {
                try {
                    app(StockService::class)->waste($record, (float) $data['quantity'], auth()->user(), $data['reason']);
                    Notification::make()->success()->title(__('inventory::inventory.actions.wasted', ['name' => $record->name, 'qty' => StockService::qty((float) $data['quantity']), 'unit' => $record->unit->short()]))->send();
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngredients::route('/'),
            'create' => CreateIngredient::route('/create'),
            'edit' => EditIngredient::route('/{record}/edit'),
        ];
    }
}
