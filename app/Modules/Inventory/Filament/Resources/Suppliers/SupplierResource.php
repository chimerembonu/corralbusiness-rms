<?php

namespace App\Modules\Inventory\Filament\Resources\Suppliers;

use App\Modules\Inventory\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Inventory\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Inventory\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Staff\Enums\Permission;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 41;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('inventory::inventory.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('inventory::inventory.supplier.supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('inventory::inventory.supplier.suppliers');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::InventoryManage->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('inventory::inventory.supplier.fields.'.$k);

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label($f('name'))->required()->maxLength(100),
                TextInput::make('contact_name')->label($f('contact_name'))->maxLength(80),
                TextInput::make('phone')->label($f('phone'))->tel()->maxLength(30),
                TextInput::make('email')->label($f('email'))->email()->maxLength(120),
                TextInput::make('address')->label($f('address'))->maxLength(255),
                TextInput::make('payment_terms')->label($f('payment_terms'))->maxLength(60),
                TextInput::make('notes')->label($f('notes'))->maxLength(255),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('inventory::inventory.supplier.fields.'.$k);

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable()->weight('bold')->description(fn (Supplier $r) => $r->contact_name),
                TextColumn::make('phone')->label($f('phone'))->placeholder('—'),
                TextColumn::make('payment_terms')->label($f('payment_terms'))->placeholder('—'),
                TextColumn::make('ingredients_count')->label($f('ingredients'))->counts('ingredients')->alignRight(),
                IconColumn::make('is_active')->label($f('is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
