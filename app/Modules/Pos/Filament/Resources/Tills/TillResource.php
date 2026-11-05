<?php

namespace App\Modules\Pos\Filament\Resources\Tills;

use App\Modules\Pos\Filament\Resources\Tills\Pages\CreateTill;
use App\Modules\Pos\Filament\Resources\Tills\Pages\EditTill;
use App\Modules\Pos\Filament\Resources\Tills\Pages\ListTills;
use App\Modules\Pos\Models\Till;
use App\Modules\Printing\Models\Printer;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\BranchResource;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Back office → Settings → Tills: name each till and link it to its receipt printer / cash drawer. */
class TillResource extends BranchResource
{
    protected static ?string $model = Till::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?int $navigationSort = 85;

    protected static ?string $recordTitleAttribute = 'name';

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('pos::pos.tills.till');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pos::pos.tills.tills');
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('pos::pos.tills.fields.'.$k);

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label($f('name'))->required()->maxLength(50),
                TextInput::make('code')->label($f('code'))->required()->maxLength(20)->alphaDash()->unique(ignoreRecord: true),
                Select::make('receipt_printer_id')->label($f('receipt_printer'))->helperText($f('receipt_printer_help'))
                    ->options(fn () => Printer::query()->forBranch()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->nullable(),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('pos::pos.tills.fields.'.$k);

        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable(),
                TextColumn::make('code')->label($f('code'))->badge()->color('gray'),
                TextColumn::make('receiptPrinter.name')->label($f('receipt_printer'))->placeholder('—'),
                IconColumn::make('is_active')->label($f('is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTills::route('/'),
            'create' => CreateTill::route('/create'),
            'edit' => EditTill::route('/{record}/edit'),
        ];
    }
}
