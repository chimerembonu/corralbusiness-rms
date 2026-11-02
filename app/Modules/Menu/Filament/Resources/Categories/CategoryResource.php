<?php

namespace App\Modules\Menu\Filament\Resources\Categories;

use App\Modules\Menu\Filament\Resources\Categories\Pages\CreateCategory;
use App\Modules\Menu\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Menu\Filament\Resources\Categories\Pages\ListCategories;
use App\Modules\Menu\Models\Category;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\AvailabilityFields;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::MenuManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('menu::menu.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('menu::menu.category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('menu::menu.categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('menu::menu.fields.name'))->required()->maxLength(80),
                TextInput::make('sort_order')->label(__('menu::menu.fields.sort_order'))->numeric()->default(0),
                TextInput::make('description')->label(__('menu::menu.fields.description'))->maxLength(255)->columnSpanFull(),
                ColorPicker::make('color')->label(__('menu::menu.fields.color')),
                Toggle::make('is_active')->label(__('menu::menu.fields.is_active'))->default(true)->inline(false),
                FileUpload::make('image_path')->label(__('menu::menu.fields.image'))->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->disk('public')->directory('menu/categories')->columnSpanFull(),
            ]),
            AvailabilityFields::make()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->label(__('menu::menu.fields.name'))->searchable()->sortable(),
                TextColumn::make('items_count')->counts('items')->label(__('menu::menu.items'))->badge(),
                TextColumn::make('availability')->label(__('menu::menu.availability.days'))
                    ->formatStateUsing(fn (Category $record) => $record->availabilityRule()->describe())
                    ->default(__('menu::menu.availability.always')),
                IconColumn::make('is_active')->label(__('menu::menu.fields.is_active'))->boolean(),
                TextColumn::make('sort_order')->label(__('menu::menu.fields.sort_order'))->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
