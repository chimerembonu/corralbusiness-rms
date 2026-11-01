<?php

namespace App\Modules\Orders\Filament\Resources\DiningTables;

use App\Modules\Orders\Enums\TableShape;
use App\Modules\Orders\Enums\TableStatus;
use App\Modules\Orders\Filament\Resources\DiningTables\Pages\CreateDiningTable;
use App\Modules\Orders\Filament\Resources\DiningTables\Pages\EditDiningTable;
use App\Modules\Orders\Filament\Resources\DiningTables\Pages\ListDiningTables;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DiningTableResource extends Resource
{
    protected static ?string $model = DiningTable::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('orders::orders.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('orders::orders.table');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders::orders.tables');
    }

    /** @return array<int, Component> shared with the floor plan "Add table" action */
    public static function fields(): array
    {
        return [
            TextInput::make('name')->label(__('orders::orders.fields.name'))->required()->maxLength(30),
            Select::make('section_id')->label(__('orders::orders.fields.section'))
                ->options(fn () => FloorSection::query()->active()->ordered()->pluck('name', 'id'))
                ->searchable(),
            TextInput::make('seats')->label(__('orders::orders.fields.seats'))->numeric()->minValue(1)->maxValue(50)->default(4)->required(),
            Select::make('shape')->label(__('orders::orders.fields.shape'))->options(TableShape::options())->default(TableShape::Square->value)->required(),
            TextInput::make('width')->label(__('orders::orders.fields.width'))->numeric()->minValue(1)->maxValue(8)->default(2)->required(),
            TextInput::make('height')->label(__('orders::orders.fields.height'))->numeric()->minValue(1)->maxValue(6)->default(2)->required(),
            Toggle::make('is_active')->label(__('orders::orders.fields.is_active'))->default(true)->inline(false),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema(self::fields()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['section', 'currentOrder', 'mergedInto']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('orders::orders.fields.name'))->searchable()->sortable(),
                TextColumn::make('section.name')->label(__('orders::orders.fields.section'))->sortable()->placeholder(__('orders::orders.no_section')),
                TextColumn::make('seats')->label(__('orders::orders.fields.seats'))->sortable(),
                TextColumn::make('shape')->label(__('orders::orders.fields.shape'))->formatStateUsing(fn (TableShape $state) => $state->label()),
                TextColumn::make('status')->label(__('orders::orders.fields.status'))->badge()
                    ->state(fn (DiningTable $record) => $record->status())
                    ->formatStateUsing(fn (TableStatus $state) => $state->label())
                    ->color(fn (TableStatus $state) => match ($state) {
                        TableStatus::Free => 'gray', TableStatus::Seated => 'success', TableStatus::Ordered => 'warning', TableStatus::Merged => 'info',
                    }),
                IconColumn::make('is_active')->label(__('orders::orders.fields.is_active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('section_id')->label(__('orders::orders.fields.section'))->relationship('section', 'name'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiningTables::route('/'),
            'create' => CreateDiningTable::route('/create'),
            'edit' => EditDiningTable::route('/{record}/edit'),
        ];
    }
}
