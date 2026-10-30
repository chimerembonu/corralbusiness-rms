<?php

namespace App\Modules\Menu\Filament\Resources\Items\RelationManagers;

use App\Modules\Menu\Enums\ItemType;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** What a combo bundle contains. Shown only on combo items. */
class ComboItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'comboItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('menu::menu.fields.combo_items');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Item && $ownerRecord->isCombo();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('item_id')->label(__('menu::menu.item'))
                ->options(fn () => Item::query()->active()->where('type', ItemType::Standard->value)->orderBy('name')->pluck('name', 'id'))
                ->searchable()->preload()->required()->live(),
            Select::make('variant_id')->label(__('menu::menu.fields.variant'))
                ->options(fn (Get $get) => $get('item_id') ? ItemVariant::query()->where('item_id', $get('item_id'))->pluck('name', 'id') : [])
                ->visible(fn (Get $get) => $get('item_id') && ItemVariant::query()->where('item_id', $get('item_id'))->exists()),
            TextInput::make('quantity')->label(__('menu::menu.fields.quantity'))->numeric()->minValue(1)->default(1)->required(),
            TextInput::make('sort_order')->label(__('menu::menu.fields.sort_order'))->numeric()->default(0),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('quantity')->label(__('menu::menu.fields.quantity')),
                TextColumn::make('item.name')->label(__('menu::menu.item')),
                TextColumn::make('variant.name')->label(__('menu::menu.fields.variant'))->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
