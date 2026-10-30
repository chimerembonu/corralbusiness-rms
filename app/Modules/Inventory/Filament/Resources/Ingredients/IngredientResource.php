<?php

namespace App\Modules\Inventory\Filament\Resources\Ingredients;

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\CreateIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\EditIngredient;
use App\Modules\Inventory\Filament\Resources\Ingredients\Pages\ListIngredients;
use App\Modules\Inventory\Models\Ingredient;
use App\Support\Filament\MoneyInput;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngredientResource extends Resource
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label(__('inventory::inventory.fields.name'))->required()->maxLength(100)->columnSpan(2),
                TextInput::make('code')->label(__('inventory::inventory.fields.code'))->maxLength(30),
                Select::make('unit')->label(__('inventory::inventory.fields.unit'))->options(Unit::options())->default(Unit::Kilogram->value)->required(),
                MoneyInput::make('cost_per_unit')->label(__('inventory::inventory.fields.cost_per_unit'))->default('0.00'),
                Toggle::make('is_active')->label(__('inventory::inventory.fields.is_active'))->default(true)->inline(false),
                TextInput::make('on_hand')->label(__('inventory::inventory.fields.on_hand'))->numeric()->default(0)->step('0.001'),
                TextInput::make('reorder_level')->label(__('inventory::inventory.fields.reorder_level'))->numeric()->default(0)->step('0.001'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('inventory::inventory.fields.name'))->searchable()->sortable()->description(fn (Ingredient $r) => $r->code),
                TextColumn::make('unit')->label(__('inventory::inventory.fields.unit'))->formatStateUsing(fn (Unit $state) => $state->value),
                TextColumn::make('cost_per_unit')->label(__('inventory::inventory.fields.cost_per_unit'))->formatStateUsing(fn ($state) => MoneyInput::format($state)),
                TextColumn::make('on_hand')->label(__('inventory::inventory.fields.on_hand'))->numeric(decimalPlaces: 1)->sortable()
                    ->color(fn (Ingredient $r) => $r->isLow() ? 'danger' : null)->weight(fn (Ingredient $r) => $r->isLow() ? 'bold' : null),
                TextColumn::make('reorder_level')->label(__('inventory::inventory.fields.reorder_level'))->numeric(decimalPlaces: 1),
                IconColumn::make('is_active')->label(__('inventory::inventory.fields.is_active'))->boolean(),
            ])
            ->filters([
                Filter::make('low')->label(__('inventory::inventory.fields.low'))->query(fn (Builder $q) => $q->lowStock()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
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
