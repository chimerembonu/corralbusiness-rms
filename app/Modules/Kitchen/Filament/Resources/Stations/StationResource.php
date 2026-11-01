<?php

namespace App\Modules\Kitchen\Filament\Resources\Stations;

use App\Modules\Kitchen\Filament\Resources\Stations\Pages\CreateStation;
use App\Modules\Kitchen\Filament\Resources\Stations\Pages\EditStation;
use App\Modules\Kitchen\Filament\Resources\Stations\Pages\ListStations;
use App\Modules\Kitchen\Models\Station;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
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

class StationResource extends Resource
{
    protected static ?string $model = Station::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('kitchen::kitchen.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('kitchen::kitchen.station_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kitchen::kitchen.stations');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('kitchen::kitchen.fields.'.$k);

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label($f('name'))->required()->maxLength(50),
                TextInput::make('code')->label($f('code'))->required()->maxLength(20)->alphaDash()->extraInputAttributes(['style' => 'text-transform: uppercase']),
                ColorPicker::make('color')->label($f('color')),
                TextInput::make('sort_order')->label($f('sort_order'))->numeric()->default(0),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
                Toggle::make('is_default')->label($f('is_default'))->inline(false),
                Toggle::make('is_expo')->label($f('is_expo'))->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('kitchen::kitchen.fields.'.$k);

        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('code')->label($f('code'))->badge()->color('gray'),
                IconColumn::make('is_default')->label($f('is_default'))->boolean(),
                IconColumn::make('is_active')->label($f('is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStations::route('/'),
            'create' => CreateStation::route('/create'),
            'edit' => EditStation::route('/{record}/edit'),
        ];
    }
}
