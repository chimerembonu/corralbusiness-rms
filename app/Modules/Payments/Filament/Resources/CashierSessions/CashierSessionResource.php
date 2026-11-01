<?php

namespace App\Modules\Payments\Filament\Resources\CashierSessions;

use App\Modules\Payments\Enums\CashierSessionStatus;
use App\Modules\Payments\Filament\Resources\CashierSessions\Pages\ListCashierSessions;
use App\Modules\Payments\Filament\Resources\CashierSessions\Pages\ViewCashierSession;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Printing\Documents\CashReportDocument;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Back office → Cash & day close → Cashier sessions (read-only; the till does the work). */
class CashierSessionResource extends Resource
{
    protected static ?string $model = CashierSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): ?string
    {
        return __('payments::payments.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('payments::payments.cash.session');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments::payments.cash.sessions');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['till', 'user', 'closedBy']);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('payments::payments.cash.fields.'.$k);

        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('number')->label($f('number'))->formatStateUsing(fn (CashierSession $r) => $r->displayNumber())->sortable(),
                TextColumn::make('business_date')->label($f('date'))->date('d M Y')->sortable(),
                TextColumn::make('till.name')->label($f('till'))->placeholder(__('payments::payments.cash.no_till')),
                TextColumn::make('user.name')->label($f('cashier'))->searchable(),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (CashierSessionStatus $state) => $state->label())
                    ->color(fn (CashierSessionStatus $state) => $state === CashierSessionStatus::Open ? 'success' : 'gray'),
                TextColumn::make('opening_float')->label($f('opening_float'))->formatStateUsing(fn ($state) => money($state))->alignRight(),
                TextColumn::make('cash_sales')->label($f('cash_sales'))->formatStateUsing(fn ($state) => money($state))->alignRight(),
                TextColumn::make('expected_cash')->label($f('expected'))->formatStateUsing(fn ($state) => money($state))->placeholder('—')->alignRight(),
                TextColumn::make('counted_cash')->label($f('counted'))->formatStateUsing(fn ($state) => money($state))->placeholder('—')->alignRight(),
                TextColumn::make('variance')->label($f('variance'))->formatStateUsing(fn ($state) => CashReportDocument::signed($state, true))->placeholder('—')->alignRight()
                    ->color(fn ($state) => $state === null ? 'gray' : ($state < 0 ? 'danger' : ($state > 0 ? 'warning' : 'success'))),
            ])
            ->filters([
                SelectFilter::make('status')->label($f('status'))->options([
                    CashierSessionStatus::Open->value => CashierSessionStatus::Open->label(),
                    CashierSessionStatus::Closed->value => CashierSessionStatus::Closed->label(),
                ]),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashierSessions::route('/'),
            'view' => ViewCashierSession::route('/{record}'),
        ];
    }
}
