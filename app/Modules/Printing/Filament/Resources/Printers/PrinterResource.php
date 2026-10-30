<?php

namespace App\Modules\Printing\Filament\Resources\Printers;

use App\Modules\Printing\Enums\PrinterDriverType;
use App\Modules\Printing\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Modules\Printing\Filament\Resources\Printers\Pages\EditPrinter;
use App\Modules\Printing\Filament\Resources\Printers\Pages\ListPrinters;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Settings\Models\Branch;
use App\Support\Tenancy\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('printing::printing.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('printing::printing.printer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('printing::printing.printers');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('printing::printing.fields.name'))->required()->maxLength(80),
                Select::make('branch_id')->label(__('menu::menu.fields.branch'))
                    ->options(fn () => Branch::query()->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => Tenancy::branchId())->required(),
                Select::make('driver')->label(__('printing::printing.fields.driver'))
                    ->options(collect(PrinterDriverType::cases())->mapWithKeys(fn (PrinterDriverType $d) => [$d->value => $d->label()]))
                    ->default(PrinterDriverType::Network->value)->required()->live(),
                TextInput::make('address')->label(__('printing::printing.fields.address'))
                    ->helperText(__('printing::printing.fields.address_hint'))->maxLength(120)
                    ->required(fn (Get $get) => $get('driver') !== PrinterDriverType::Browser->value)
                    ->visible(fn (Get $get) => $get('driver') !== PrinterDriverType::Browser->value),
                Select::make('paper_width')->label(__('printing::printing.fields.paper_width'))->options([80 => '80 mm', 58 => '58 mm'])->default(80)->required(),
                Toggle::make('is_active')->label(__('printing::printing.fields.is_active'))->default(true)->inline(false),
                Toggle::make('prints_receipts')->label(__('printing::printing.fields.prints_receipts'))->inline(false),
                Toggle::make('prints_kitchen_tickets')->label(__('printing::printing.fields.prints_kitchen_tickets'))->inline(false),
                Toggle::make('has_cash_drawer')->label(__('printing::printing.fields.has_cash_drawer'))->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('printing::printing.fields.name'))->searchable()->sortable(),
                TextColumn::make('driver')->label(__('printing::printing.fields.driver'))->badge()->formatStateUsing(fn (PrinterDriverType $state) => $state->label()),
                TextColumn::make('address')->label(__('printing::printing.fields.address'))->placeholder('—'),
                TextColumn::make('paper_width')->label(__('printing::printing.fields.paper_width'))->suffix(' mm'),
                IconColumn::make('prints_receipts')->label(__('printing::printing.fields.prints_receipts'))->boolean(),
                IconColumn::make('prints_kitchen_tickets')->label(__('printing::printing.fields.prints_kitchen_tickets'))->boolean(),
                IconColumn::make('is_active')->label(__('printing::printing.fields.is_active'))->boolean(),
                TextColumn::make('jobs_count')->counts('jobs')->label(__('printing::printing.print_jobs'))->badge()->color('gray'),
            ])
            ->recordActions([
                Action::make('testPrint')->label(__('printing::printing.actions.test_print'))->icon(Heroicon::OutlinedPrinter)->color('success')
                    ->action(function (Printer $record) {
                        app(PrintService::class)->testPrint($record);
                        Notification::make()->title(__('printing::printing.actions.test_queued', ['printer' => $record->name]))->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrinters::route('/'),
            'create' => CreatePrinter::route('/create'),
            'edit' => EditPrinter::route('/{record}/edit'),
        ];
    }
}
