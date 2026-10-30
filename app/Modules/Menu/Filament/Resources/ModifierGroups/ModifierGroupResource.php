<?php

namespace App\Modules\Menu\Filament\Resources\ModifierGroups;

use App\Modules\Menu\Filament\Resources\ModifierGroups\Pages\CreateModifierGroup;
use App\Modules\Menu\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Modules\Menu\Filament\Resources\ModifierGroups\Pages\ListModifierGroups;
use App\Modules\Menu\Models\ModifierGroup;
use App\Support\Filament\MoneyInput;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModifierGroupResource extends Resource
{
    protected static ?string $model = ModifierGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('menu::menu.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('menu::menu.modifier_group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('menu::menu.modifier_groups');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label(__('menu::menu.fields.name'))->required()->maxLength(80)->columnSpan(2),
                TextInput::make('sort_order')->label(__('menu::menu.fields.sort_order'))->numeric()->default(0),
                TextInput::make('description')->label(__('menu::menu.fields.description'))->maxLength(255)->columnSpanFull(),
                TextInput::make('min_select')->label(__('menu::menu.fields.min_select'))->numeric()->minValue(0)->default(0)->required(),
                TextInput::make('max_select')->label(__('menu::menu.fields.max_select'))->numeric()->minValue(0)->default(1)->required(),
                Toggle::make('allow_quantity')->label(__('menu::menu.fields.allow_quantity'))->inline(false),
                Toggle::make('is_active')->label(__('menu::menu.fields.is_active'))->default(true)->inline(false),
            ]),
            Section::make(__('menu::menu.fields.modifiers'))->schema([
                Repeater::make('modifiers')
                    ->relationship('modifiers')
                    ->orderColumn('sort_order')
                    ->reorderable()
                    ->columns(4)
                    ->defaultItems(1)
                    ->schema([
                        TextInput::make('name')->label(__('menu::menu.fields.name'))->required()->maxLength(80)->columnSpan(2),
                        MoneyInput::make('price')->label(__('menu::menu.fields.extra_price'))->default('0.00')->required(),
                        Toggle::make('is_default')->label(__('menu::menu.fields.is_default'))->inline(false),
                        Toggle::make('is_sold_out')->label(__('menu::menu.fields.is_sold_out'))->inline(false),
                        Toggle::make('is_active')->label(__('menu::menu.fields.is_active'))->default(true)->inline(false),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('menu::menu.fields.name'))->searchable()->sortable()
                    ->description(fn (ModifierGroup $record) => $record->rule()),
                TextColumn::make('modifiers_count')->counts('modifiers')->label(__('menu::menu.fields.modifiers'))->badge(),
                TextColumn::make('items_count')->counts('items')->label(__('menu::menu.items'))->badge()->color('gray'),
                IconColumn::make('is_active')->label(__('menu::menu.fields.is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModifierGroups::route('/'),
            'create' => CreateModifierGroup::route('/create'),
            'edit' => EditModifierGroup::route('/{record}/edit'),
        ];
    }
}
