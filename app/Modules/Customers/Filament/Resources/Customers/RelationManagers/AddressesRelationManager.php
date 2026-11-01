<?php

namespace App\Modules\Customers\Filament\Resources\Customers\RelationManagers;

use App\Modules\Customers\Models\CustomerAddress;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('customers::customers.fields.addresses');
    }

    public function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('customers::customers.fields.'.$k);

        return $schema->components([
            TextInput::make('label')->label($f('label'))->maxLength(40),
            TextInput::make('address')->label($f('address'))->required()->maxLength(255),
            TextInput::make('notes')->label($f('directions'))->maxLength(160),
            Toggle::make('is_default')->label($f('is_default'))->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        $f = fn (string $k) => __('customers::customers.fields.'.$k);

        return $table
            ->columns([
                TextColumn::make('label')->label($f('label'))->placeholder('—'),
                TextColumn::make('address')->label($f('address'))->wrap(),
                TextColumn::make('notes')->label($f('directions'))->placeholder('—'),
                IconColumn::make('is_default')->label($f('is_default'))->boolean(),
            ])
            ->headerActions([CreateAction::make()->after(fn (CustomerAddress $record) => self::keepOneDefault($record))])
            ->recordActions([EditAction::make()->after(fn (CustomerAddress $record) => self::keepOneDefault($record)), DeleteAction::make()]);
    }

    private static function keepOneDefault(CustomerAddress $record): void
    {
        if ($record->is_default) {
            CustomerAddress::query()->where('customer_id', $record->customer_id)->whereKeyNot($record->id)->update(['is_default' => false]);
        }
    }
}
