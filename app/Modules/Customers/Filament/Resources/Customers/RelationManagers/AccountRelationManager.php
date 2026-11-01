<?php

namespace App\Modules\Customers\Filament\Resources\Customers\RelationManagers;

use App\Modules\Customers\Enums\AccountTransactionType;
use App\Modules\Customers\Models\AccountTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only account ledger. */
class AccountRelationManager extends RelationManager
{
    protected static string $relationship = 'accountTransactions';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('customers::customers.fields.statement');
    }

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->allow_account;
    }

    public function table(Table $table): Table
    {
        $c = fn (string $k) => __('customers::customers.account.columns.'.$k);

        return $table
            ->defaultSort('happened_at', 'desc')
            ->columns([
                TextColumn::make('happened_at')->label($c('when'))->dateTime('d M Y H:i'),
                TextColumn::make('type')->label($c('type'))->badge()->formatStateUsing(fn (AccountTransactionType $state) => $state->label())
                    ->color(fn (AccountTransactionType $state) => match ($state) {
                        AccountTransactionType::Charge => 'warning', AccountTransactionType::Payment => 'success', default => 'gray'
                    }),
                TextColumn::make('notes')->label($c('detail'))->placeholder('—')->description(fn (AccountTransaction $r) => $r->reference),
                TextColumn::make('amount')->label($c('amount'))->formatStateUsing(fn (AccountTransaction $r) => ($r->amount > 0 ? '+' : '−').money($r->money()))->alignRight(),
                TextColumn::make('balance_after')->label($c('balance'))->formatStateUsing(fn ($state) => money((int) $state))->alignRight(),
                TextColumn::make('user.name')->label($c('by'))->placeholder('—'),
            ])
            ->recordActions([])
            ->headerActions([]);
    }
}
