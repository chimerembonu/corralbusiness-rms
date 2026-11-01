<?php

namespace App\Modules\Customers\Filament\Resources\Customers\RelationManagers;

use App\Modules\Orders\Models\Order;
use App\Support\Filament\MoneyInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only order history. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('customers::customers.fields.history');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('pos::pos.today.number'))->formatStateUsing(fn (Order $r) => '#'.$r->displayNumber()),
                TextColumn::make('opened_at')->label(__('pos::pos.today.time'))->dateTime('d M Y H:i'),
                TextColumn::make('type')->label(__('pos::pos.receipt.type'))->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('status')->label(__('pos::pos.today.status'))->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('total')->label(__('pos::pos.today.total'))->formatStateUsing(fn ($state) => MoneyInput::format($state))->alignRight(),
            ])
            ->recordActions([])
            ->headerActions([]);
    }
}
