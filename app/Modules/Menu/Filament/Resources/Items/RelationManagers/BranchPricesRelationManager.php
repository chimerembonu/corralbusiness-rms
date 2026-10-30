<?php

namespace App\Modules\Menu\Filament\Resources\Items\RelationManagers;

use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Settings\Models\Branch;
use App\Support\Filament\MoneyInput;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Per-branch price overrides and "not sold at this branch". */
class BranchPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'branchPrices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('menu::menu.fields.branch_prices');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_id')->label(__('menu::menu.fields.branch'))
                ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))->required(),
            Select::make('variant_id')->label(__('menu::menu.fields.variant'))
                ->options(fn () => ItemVariant::query()->where('item_id', $this->getOwnerRecord()->getKey())->pluck('name', 'id'))
                ->visible(fn () => $this->getOwnerRecord()->variants()->exists()),
            MoneyInput::make('price')->label(__('menu::menu.fields.branch_price')),
            Toggle::make('is_available')->label(__('menu::menu.fields.is_available'))->default(true)->inline(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch.name')->label(__('menu::menu.fields.branch')),
                TextColumn::make('variant.name')->label(__('menu::menu.fields.variant'))->placeholder('—'),
                TextColumn::make('price')->label(__('menu::menu.fields.price'))->formatStateUsing(fn ($state) => MoneyInput::format($state))->placeholder('—'),
                IconColumn::make('is_available')->label(__('menu::menu.fields.is_available'))->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
