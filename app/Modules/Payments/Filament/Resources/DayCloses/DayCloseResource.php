<?php

namespace App\Modules\Payments\Filament\Resources\DayCloses;

use App\Modules\Payments\Enums\DayCloseStatus;
use App\Modules\Payments\Filament\Resources\DayCloses\Pages\ListDayCloses;
use App\Modules\Payments\Filament\Resources\DayCloses\Pages\ViewDayClose;
use App\Modules\Payments\Models\DayClose;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Errors\FriendlyError;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/** Back office → Cash & day close → Day closes (Z reports). */
class DayCloseResource extends Resource
{
    protected static ?string $model = DayClose::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static ?int $navigationSort = 51;

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->canAny([Permission::ReportsView->value, Permission::DayClose->value]) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('payments::payments.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('payments::payments.day.close');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments::payments.day.closes');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['closedBy', 'reopenedBy']);
    }

    public static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('payments::payments.day.reopen'))
            ->icon(Heroicon::LockOpen)
            ->color('danger')
            ->visible(fn (DayClose $record) => $record->isClosed() && auth()->user()->can(Permission::DayReopen->value))
            ->schema([TextInput::make('reason')->label(__('payments::payments.day.fields.reopen_reason'))->required()->maxLength(255)])
            ->requiresConfirmation()
            ->action(function (DayClose $record, array $data) {
                try {
                    app(DayCloseService::class)->reopen($record, auth()->user(), $data['reason']);
                    Notification::make()->success()->title(__('payments::payments.day.reopened'))->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(FriendlyError::message($e))->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('payments::payments.day.fields.'.$k);

        return $table
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')->label($f('number'))->formatStateUsing(fn (DayClose $r) => $r->displayNumber())->sortable(),
                TextColumn::make('business_date')->label($f('date'))->date('d M Y')->sortable(),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (DayCloseStatus $state) => $state->label())
                    ->color(fn (DayCloseStatus $state) => $state === DayCloseStatus::Closed ? 'success' : 'warning'),
                TextColumn::make('closed_at')->label($f('closed_at'))->dateTime('d M H:i'),
                TextColumn::make('closedBy.name')->label($f('closed_by')),
                TextColumn::make('report.gross_sales')->label($f('gross_sales'))->formatStateUsing(fn ($state) => money((int) $state))->alignRight(),
                TextColumn::make('report.orders_paid')->label($f('orders'))->alignRight(),
                TextColumn::make('reopenedBy.name')->label($f('reopened_by'))->placeholder('—'),
            ])
            ->recordActions([ViewAction::make(), self::reopenAction()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDayCloses::route('/'),
            'view' => ViewDayClose::route('/{record}'),
        ];
    }
}
