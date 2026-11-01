<?php

namespace App\Modules\Staff\Filament\Pages;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Exports\TimesheetExport;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\RosterService;
use App\Modules\Staff\Services\TimeClockService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/** Back office → Staff → Timesheets: scheduled vs worked hours, corrections, Excel export. */
class TimesheetsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'timesheets';

    protected string $view = 'staff::filament.timesheets';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** @var array<int, string> user ids whose entries are shown */
    public array $expanded = [];

    public static function getNavigationGroup(): ?string
    {
        return __('staff::staff.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff::staff.timesheets.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::StaffManage->value) ?? false;
    }

    public function getTitle(): string
    {
        return __('staff::staff.timesheets.title');
    }

    public function mount(): void
    {
        if ($this->from === '' || $this->to === '') {
            $this->preset('this_week');
        }
    }

    public function preset(string $which): void
    {
        $now = CarbonImmutable::now();
        [$from, $to] = match ($which) {
            'last_week' => [RosterService::weekStart()->subWeek(), RosterService::weekStart()->subDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            default => [RosterService::weekStart(), RosterService::weekStart()->addDays(6)],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function toggle(string $userId): void
    {
        $this->expanded = in_array($userId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$userId]))
            : [...$this->expanded, $userId];
    }

    public function rows(): Collection
    {
        $clock = app(TimeClockService::class);
        $clock->autoClockOut();

        return $clock->timesheet($this->from, $this->to);
    }

    public function whoIsIn(): Collection
    {
        return app(TimeClockService::class)->whoIsIn();
    }

    /** @return array<int, Component> */
    private function entryFields(): array
    {
        $c = fn (string $k) => __('staff::staff.timesheets.columns.'.$k);

        return [
            Select::make('user_id')->label($c('staff'))->options(fn () => app(RosterService::class)->staff()->pluck('name', 'id')->all())->required()->searchable(),
            DateTimePicker::make('clocked_in_at')->label($c('in'))->seconds(false)->required(),
            DateTimePicker::make('clocked_out_at')->label($c('out'))->seconds(false)->helperText(__('staff::staff.timesheets.still_in')),
            TextInput::make('break_minutes')->label($c('break'))->numeric()->minValue(0)->default(0),
            TextInput::make('reason')->label(__('staff::staff.timesheets.reason'))->required()->maxLength(255),
        ];
    }

    private function saveEntry(?TimeEntry $entry, array $data): void
    {
        try {
            app(TimeClockService::class)->adjust(
                $entry,
                User::findOrFail($data['user_id']),
                CarbonImmutable::parse($data['clocked_in_at']),
                filled($data['clocked_out_at'] ?? null) ? CarbonImmutable::parse($data['clocked_out_at']) : null,
                auth()->user(),
                $data['reason'],
                (int) ($data['break_minutes'] ?? 0),
            );
            Notification::make()->success()->title(__('staff::staff.timesheets.saved'))->send();
        } catch (TimeClockException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function addEntryAction(): Action
    {
        return Action::make('addEntry')
            ->label(__('staff::staff.timesheets.add_entry'))
            ->icon(Heroicon::Plus)
            ->schema($this->entryFields())
            ->fillForm(fn (array $arguments) => ['user_id' => $arguments['user'] ?? null, 'break_minutes' => 0])
            ->action(fn (array $data) => $this->saveEntry(null, $data));
    }

    public function editEntryAction(): Action
    {
        return Action::make('editEntry')
            ->label(__('staff::staff.timesheets.edit_entry'))
            ->modalHeading(__('staff::staff.timesheets.edit_entry'))
            ->schema($this->entryFields())
            ->fillForm(function (array $arguments) {
                $entry = TimeEntry::findOrFail($arguments['entry']);

                return ['user_id' => $entry->user_id, 'clocked_in_at' => $entry->clocked_in_at, 'clocked_out_at' => $entry->clocked_out_at, 'break_minutes' => $entry->break_minutes];
            })
            ->extraModalFooterActions(fn (Action $action) => [
                $action->makeModalSubmitAction('delete', arguments: ['delete' => true])->label(__('staff::staff.timesheets.delete_entry'))->color('danger'),
            ])
            ->action(function (array $data, array $arguments) {
                $entry = TimeEntry::findOrFail($arguments['entry']);
                if ($arguments['delete'] ?? false) {
                    app(TimeClockService::class)->delete($entry, auth()->user(), $data['reason'] ?? '');
                    Notification::make()->success()->title(__('staff::staff.timesheets.deleted'))->send();

                    return;
                }
                $this->saveEntry($entry, $data);
            });
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label(__('staff::staff.timesheets.export'))
            ->icon(Heroicon::ArrowDownTray)
            ->color('gray')
            ->action(fn () => Excel::download(new TimesheetExport($this->from, $this->to), "timesheets-{$this->from}-to-{$this->to}.xlsx"));
    }

    protected function getHeaderActions(): array
    {
        return [$this->addEntryAction(), $this->exportAction()];
    }
}
