<?php

namespace App\Modules\Inventory\Filament\Resources\Purchases;

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Modules\Inventory\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Modules\Inventory\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\MoneyInput;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Purchase orders and goods received. */
class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.purchase.purchase');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.purchase.purchases');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::InventoryManage->value) ?? false;
    }

    public static function canEdit($record): bool
    {
        return $record->isOpen();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'receivedBy']);
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('inventory::inventory.purchase.fields.'.$k);

        return $schema->components([
            Section::make()->columns(3)->schema([
                Select::make('supplier_id')->label($f('supplier'))->options(fn () => Supplier::query()->active()->orderBy('name')->pluck('name', 'id')->all())->searchable()->nullable(),
                DatePicker::make('expected_on')->label($f('expected_on')),
                TextInput::make('notes')->label($f('notes'))->maxLength(255),
            ]),
            Section::make($f('lines'))->schema([
                Repeater::make('lines')->label('')->columns(4)->minItems(1)->defaultItems(1)->addActionLabel(__('inventory::inventory.purchase.fields.ingredient'))
                    ->schema([
                        Select::make('ingredient_id')->label($f('ingredient'))->options(fn () => Ingredient::query()->active()->orderBy('name')->get()->mapWithKeys(fn (Ingredient $i) => [$i->id => $i->name.' ('.$i->unit->short().')'])->all())
                            ->required()->searchable()->live()->columnSpan(2)
                            ->afterStateUpdated(fn (Set $set, $state) => $set('unit_cost', $state ? Ingredient::query()->find($state)?->cost_per_unit->toDecimal() : null)),
                        TextInput::make('quantity')->label($f('quantity'))->numeric()->required()->minValue(0.001)->step('0.001')
                            ->suffix(fn (Get $get) => $get('ingredient_id') ? Ingredient::query()->find($get('ingredient_id'))?->unit->short() : null),
                        MoneyInput::make('unit_cost')->label($f('unit_cost')),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('inventory::inventory.purchase.fields.'.$k);

        return $table
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')->label($f('number'))->formatStateUsing(fn (Purchase $r) => $r->displayNumber())->sortable(),
                TextColumn::make('supplier.name')->label($f('supplier'))->placeholder('—')->searchable(),
                TextColumn::make('status')->label($f('status'))->badge()->formatStateUsing(fn (PurchaseStatus $state) => $state->label())
                    ->color(fn (PurchaseStatus $state) => match ($state) {
                        PurchaseStatus::Received => 'success', PurchaseStatus::Ordered => 'info', PurchaseStatus::Cancelled => 'gray', default => 'warning'
                    }),
                TextColumn::make('expected_on')->label($f('expected_on'))->date('d M')->placeholder('—'),
                TextColumn::make('received_at')->label($f('received_at'))->dateTime('d M H:i')->placeholder('—'),
                TextColumn::make('total')->label($f('total'))->formatStateUsing(fn ($state) => MoneyInput::format($state))->alignRight(),
                TextColumn::make('reference')->label($f('reference'))->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->label($f('status'))->options(array_combine(array_map(fn (PurchaseStatus $s) => $s->value, PurchaseStatus::cases()), array_map(fn (PurchaseStatus $s) => $s->label(), PurchaseStatus::cases())))])
            ->recordActions([self::receiveAction(), self::markOrderedAction(), EditAction::make()->visible(fn (Purchase $r) => $r->isOpen()), self::cancelAction()]);
    }

    public static function markOrderedAction(): Action
    {
        return Action::make('markOrdered')->label(__('inventory::inventory.purchase.actions.mark_ordered'))->icon(Heroicon::PaperAirplane)->color('info')
            ->visible(fn (Purchase $r) => $r->status === PurchaseStatus::Draft)
            ->action(function (Purchase $record) {
                app(PurchaseService::class)->markOrdered($record, auth()->user());
                Notification::make()->success()->title(__('inventory::inventory.purchase.actions.ordered'))->send();
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label(__('inventory::inventory.purchase.actions.cancel'))->icon(Heroicon::XMark)->color('gray')->requiresConfirmation()
            ->visible(fn (Purchase $r) => $r->isOpen())
            ->action(function (Purchase $record) {
                app(PurchaseService::class)->cancel($record, auth()->user());
                Notification::make()->success()->title(__('inventory::inventory.purchase.actions.cancelled'))->send();
            });
    }

    /** Goods received: one quantity box per ordered line, prefilled with what was ordered. */
    public static function receiveAction(): Action
    {
        $f = fn (string $k) => __('inventory::inventory.purchase.fields.'.$k);

        return Action::make('receive')->label(__('inventory::inventory.purchase.actions.receive'))->icon(Heroicon::ArchiveBoxArrowDown)->color('success')
            ->visible(fn (Purchase $r) => $r->isOpen())
            ->modalDescription(__('inventory::inventory.purchase.actions.receive_help'))
            ->fillForm(fn (Purchase $record) => [
                'reference' => $record->reference,
                'received' => $record->lines()->with('ingredient')->get()->mapWithKeys(fn (PurchaseLine $l) => [$l->id => (float) $l->quantity_ordered])->all(),
            ])
            ->schema(function (Purchase $record) use ($f) {
                $fields = [TextInput::make('reference')->label($f('reference'))->maxLength(60)];
                foreach ($record->lines()->with('ingredient')->get() as $line) {
                    $fields[] = TextInput::make('received.'.$line->id)
                        ->label($line->ingredient->name.' · '.$f('quantity').' '.StockService::qty((float) $line->quantity_ordered).' '.$line->ingredient->unit->short().' @ '.money($line->unit_cost))
                        ->numeric()->minValue(0)->step('0.001')->suffix($line->ingredient->unit->short());
                }

                return $fields;
            })
            ->action(function (Purchase $record, array $data) {
                try {
                    $purchase = app(PurchaseService::class)->receive($record, $data['received'] ?? [], auth()->user(), $data['reference'] ?? null);
                    Notification::make()->success()->title(__('inventory::inventory.purchase.actions.received', ['number' => $purchase->displayNumber()]))->send();
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'edit' => EditPurchase::route('/{record}/edit'),
        ];
    }
}
