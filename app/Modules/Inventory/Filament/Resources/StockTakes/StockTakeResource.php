<?php

namespace App\Modules\Inventory\Filament\Resources\StockTakes;

use App\Modules\Inventory\Enums\StockTakeStatus;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Filament\Resources\StockTakes\Pages\CountStockTake;
use App\Modules\Inventory\Filament\Resources\StockTakes\Pages\ListStockTakes;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Services\StockTakeService;
use App\Modules\Printing\Documents\CashReportDocument;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Support\Filament\BranchResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockTakeResource extends BranchResource
{
    protected static ?string $model = StockTake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 43;

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.stock_take.stock_take');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.stock_take.stock_takes');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAny([Permission::InventoryManage->value, Permission::InventoryCount->value]) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['startedBy', 'completedBy'])->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('counted')]);
    }

    public static function startAction(): Action
    {
        return Action::make('start')->label(__('inventory::inventory.stock_take.actions.start'))->icon(Heroicon::Plus)
            ->schema([
                Select::make('category')->label(__('inventory::inventory.stock_take.fields.category'))->placeholder(__('inventory::inventory.stock_take.fields.all'))
                    ->options(fn () => array_combine(app(StockTakeService::class)->categories(), app(StockTakeService::class)->categories()))->native(false),
                TextInput::make('notes')->label(__('inventory::inventory.stock_take.fields.notes'))->maxLength(255),
            ])
            ->action(function (array $data) {
                try {
                    $take = app(StockTakeService::class)->start(auth()->user(), $data['category'] ?? null);
                    if (filled($data['notes'] ?? null)) {
                        $take->forceFill(['notes' => $data['notes']])->save();
                    }
                    Notification::make()->success()->title(__('inventory::inventory.stock_take.actions.started', ['number' => $take->displayNumber(), 'count' => $take->lines()->count()]))->send();

                    return redirect(self::getUrl('count', ['record' => $take]));
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')->label(__('inventory::inventory.stock_take.actions.complete'))->icon(Heroicon::CheckCircle)->color('success')
            ->visible(fn (StockTake $record) => $record->isOpen() && auth()->user()->can(Permission::InventoryManage->value))
            ->requiresConfirmation()->modalDescription(__('inventory::inventory.stock_take.actions.complete_help'))
            ->action(function (StockTake $record) {
                try {
                    $counted = $record->lines()->whereNotNull('counted')->count();
                    app(StockTakeService::class)->complete($record, auth()->user());
                    Notification::make()->success()->title(__('inventory::inventory.stock_take.actions.completed', ['number' => $record->displayNumber(), 'count' => $counted]))->send();
                } catch (InventoryException|ManagerPinRequiredException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label(__('inventory::inventory.stock_take.actions.cancel'))->icon(Heroicon::XMark)->color('gray')->requiresConfirmation()
            ->visible(fn (StockTake $record) => $record->isOpen() && auth()->user()->can(Permission::InventoryManage->value))
            ->action(function (StockTake $record) {
                app(StockTakeService::class)->cancel($record, auth()->user());
                Notification::make()->success()->title(__('inventory::inventory.stock_take.actions.cancelled'))->send();
            });
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('inventory::inventory.stock_take.fields.'.$k);

        return $table
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')->label($f('number'))->formatStateUsing(fn (StockTake $r) => $r->displayNumber())->sortable(),
                TextColumn::make('category')->label($f('category'))->placeholder($f('all')),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (StockTakeStatus $state) => $state->label())
                    ->color(fn (StockTakeStatus $state) => match ($state) {
                        StockTakeStatus::Completed => 'success', StockTakeStatus::Open => 'warning', default => 'gray'
                    }),
                TextColumn::make('progress')->label($f('progress'))->state(fn (StockTake $r) => $r->counted_count.' / '.$r->lines_count)->alignRight(),
                TextColumn::make('started_at')->label($f('started_at'))->dateTime('d M H:i'),
                TextColumn::make('startedBy.name')->label($f('started_by'))->placeholder('—'),
                TextColumn::make('completed_at')->label($f('completed_at'))->dateTime('d M H:i')->placeholder('—'),
                TextColumn::make('variance_value')->label($f('variance_value'))->formatStateUsing(fn (StockTake $r) => $r->status === StockTakeStatus::Completed ? CashReportDocument::signed($r->variance_value, true) : '—')->alignRight()
                    ->color(fn (StockTake $r) => $r->variance_value < 0 ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('count')->label(__('inventory::inventory.stock_take.actions.count'))->icon(Heroicon::PencilSquare)->url(fn (StockTake $r) => self::getUrl('count', ['record' => $r])),
                self::completeAction(),
                self::cancelAction(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTakes::route('/'),
            'count' => CountStockTake::route('/{record}'),
        ];
    }
}
