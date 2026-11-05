<?php

namespace App\Modules\Orders\Filament\Resources\FloorSections;

use App\Modules\Orders\Filament\Resources\FloorSections\Pages\CreateFloorSection;
use App\Modules\Orders\Filament\Resources\FloorSections\Pages\EditFloorSection;
use App\Modules\Orders\Filament\Resources\FloorSections\Pages\ListFloorSections;
use App\Modules\Orders\Models\FloorSection;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\BranchResource;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FloorSectionResource extends BranchResource
{
    protected static ?string $model = FloorSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 32;

    protected static ?string $recordTitleAttribute = 'name';

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('orders::orders.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('orders::orders.section');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders::orders.sections');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('orders::orders.fields.name'))->required()->maxLength(50),
                TextInput::make('sort_order')->label(__('orders::orders.fields.sort_order'))->numeric()->default(0),
                ColorPicker::make('color')->label(__('orders::orders.fields.color')),
                Toggle::make('is_active')->label(__('orders::orders.fields.is_active'))->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->label(__('orders::orders.fields.name'))->searchable()->sortable(),
                TextColumn::make('tables_count')->counts('tables')->label(__('orders::orders.tables'))->badge(),
                IconColumn::make('is_active')->label(__('orders::orders.fields.is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFloorSections::route('/'),
            'create' => CreateFloorSection::route('/create'),
            'edit' => EditFloorSection::route('/{record}/edit'),
        ];
    }
}
