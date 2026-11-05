<?php

namespace App\Modules\Printing\Filament\Resources\PrintJobs;

use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\PrintJobType;
use App\Modules\Printing\Filament\Resources\PrintJobs\Pages\ListPrintJobs;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Filament\BranchResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PrintJobResource extends BranchResource
{
    protected static ?string $model = PrintJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 51;

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('printing::printing.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('printing::printing.print_job');
    }

    public static function getPluralModelLabel(): string
    {
        return __('printing::printing.print_jobs');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('created_at')->label(__('printing::printing.fields.created_at'))->dateTime('d/m H:i:s')->sortable(),
                TextColumn::make('printer.name')->label(__('printing::printing.fields.printer')),
                TextColumn::make('type')->label(__('printing::printing.fields.type'))->badge()->formatStateUsing(fn (PrintJobType $state) => $state->label()),
                TextColumn::make('status')->label(__('printing::printing.fields.status'))->badge()
                    ->formatStateUsing(fn (PrintJobStatus $state) => $state->label())
                    ->color(fn (PrintJobStatus $state) => match ($state) {
                        PrintJobStatus::Printed => 'success',
                        PrintJobStatus::Failed => 'danger',
                        PrintJobStatus::WaitingBrowser => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attempts')->label(__('printing::printing.fields.attempts')),
                TextColumn::make('last_error')->label(__('printing::printing.fields.last_error'))->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('printed_at')->label(__('printing::printing.fields.printed_at'))->dateTime('d/m H:i:s')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('printing::printing.fields.status'))
                    ->options(collect(PrintJobStatus::cases())->mapWithKeys(fn (PrintJobStatus $s) => [$s->value => $s->label()])),
            ])
            ->recordActions([
                Action::make('retry')->label(__('printing::printing.actions.retry'))->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (PrintJob $record) => $record->status === PrintJobStatus::Failed)
                    ->action(function (PrintJob $record) {
                        app(PrintService::class)->retry($record);
                        Notification::make()->title(__('printing::printing.actions.retried'))->success()->send();
                    }),
                Action::make('open')->label(__('printing::printing.actions.open'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (PrintJob $record) => route('printing.job', $record))->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPrintJobs::route('/')];
    }
}
