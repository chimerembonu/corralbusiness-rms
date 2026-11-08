<?php

namespace App\Modules\Inventory\Filament\Resources\StockMovements;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\BranchResource;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only history of every stock change. */
class StockMovementResource extends BranchResource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.movements.movement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.movements.title');
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
        return parent::getEloquentQuery()->with(['ingredient', 'item', 'user']);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('inventory::inventory.movements.'.$k);

        return $table
            ->defaultSort('happened_at', 'desc')
            ->columns([
                TextColumn::make('happened_at')->label($f('when'))->dateTime('d M H:i')->sortable(),
                TextColumn::make('subject')->label($f('what'))->state(fn (StockMovement $r) => $r->subjectName())->searchable(query: fn (Builder $q, string $search) => $q->whereHas('ingredient', fn ($i) => $i->where('name', 'like', "%{$search}%"))->orWhereHas('item', fn ($i) => $i->where('name', 'like', "%{$search}%"))),
                TextColumn::make('type')->label($f('type'))->badge()->formatStateUsing(fn (StockMovementType $state) => $state->label())
                    ->color(fn (StockMovementType $state) => match ($state) {
                        StockMovementType::Purchase, StockMovementType::Opening, StockMovementType::Void => 'success', StockMovementType::Wastage => 'warning', StockMovementType::Sale => 'gray', default => 'info'
                    }),
                TextColumn::make('quantity')->label($f('quantity'))->formatStateUsing(fn (StockMovement $r) => ((float) $r->quantity > 0 ? '+' : '').StockService::qty((float) $r->quantity).' '.$r->unitShort())->alignRight()
                    ->color(fn (StockMovement $r) => (float) $r->quantity < 0 ? 'danger' : 'success'),
                TextColumn::make('on_hand_after')->label($f('after'))->formatStateUsing(fn (StockMovement $r) => StockService::qty((float) $r->on_hand_after))->alignRight(),
                TextColumn::make('reason')->label($f('reason'))->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label($f('by'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('ingredient_id')->label(__('inventory::inventory.ingredient'))->options(fn () => Ingredient::query()->forBranch()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                SelectFilter::make('type')->label($f('type'))->options(array_combine(array_map(fn (StockMovementType $t) => $t->value, StockMovementType::cases()), array_map(fn (StockMovementType $t) => $t->label(), StockMovementType::cases()))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
