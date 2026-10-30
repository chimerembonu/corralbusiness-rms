<?php

namespace App\Modules\Menu\Filament\Resources\Items\RelationManagers;

use App\Support\Filament\MoneyInput;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Sizes / portions of an item, each with its own price. */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('menu::menu.fields.variants');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('menu::menu.fields.variant_name'))->required()->maxLength(60),
            MoneyInput::make('price')->label(__('menu::menu.fields.price'))->required(),
            TextInput::make('code')->label(__('menu::menu.fields.code'))->maxLength(30),
            TextInput::make('sort_order')->label(__('menu::menu.fields.sort_order'))->numeric()->default(0),
            Toggle::make('is_default')->label(__('menu::menu.fields.is_default'))->inline(false),
            Toggle::make('is_active')->label(__('menu::menu.fields.is_active'))->default(true)->inline(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('menu::menu.fields.variant_name')),
                TextColumn::make('price')->label(__('menu::menu.fields.price'))->formatStateUsing(fn ($state) => MoneyInput::format($state)),
                IconColumn::make('is_default')->label(__('menu::menu.fields.is_default'))->boolean(),
                IconColumn::make('is_active')->label(__('menu::menu.fields.is_active'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
