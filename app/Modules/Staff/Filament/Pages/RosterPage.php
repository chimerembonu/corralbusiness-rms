<?php

namespace App\Modules\Staff\Filament\Pages;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\RosterService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/** Back office → Staff → Roster: a week at a glance, staff down the side, days across. */
class RosterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'roster';

    protected string $view = 'staff::filament.roster';

    /** Monday of the week shown, Y-m-d. */
    #[Url]
    public string $week = '';

    public static function getNavigationGroup(): ?string
    {
        return __('staff::staff.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff::staff.roster.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::StaffManage->value) ?? false;
    }

    public function getTitle(): string
    {
        return __('staff::staff.roster.title');
    }

    public function getSubheading(): ?string
    {
        return __('staff::staff.roster.week_of', ['date' => $this->weekStart()->translatedFormat('j F Y')]);
    }

    public function mount(): void
    {
        $this->week = RosterService::weekStart($this->week ?: null)->toDateString();
    }

    public function weekStart(): CarbonImmutable
    {
        return RosterService::weekStart($this->week);
    }

    public function previousWeek(): void
    {
        $this->week = $this->weekStart()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = $this->weekStart()->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = RosterService::weekStart()->toDateString();
    }

    /** @return Collection<int, CarbonImmutable> */
    public function days(): Collection
    {
        return collect(range(0, 6))->map(fn (int $i) => $this->weekStart()->addDays($i));
    }

    public function staff(): Collection
    {
        return app(RosterService::class)->staff();
    }

    /** @return Collection<string, Collection<int, RosterShift>> "userId|Y-m-d" → shifts */
    public function shifts(): Collection
    {
        return app(RosterService::class)->week($this->weekStart())->groupBy(fn (RosterShift $s) => $s->user_id.'|'.$s->shift_date->toDateString());
    }

    /** @return array<int, Component> */
    private function shiftFields(): array
    {
        $r = fn (string $k) => __('staff::staff.roster.'.$k);

        return [
            Select::make('user_id')->label($r('staff'))->options(fn () => $this->staff()->pluck('name', 'id')->all())->required()->searchable(),
            DatePicker::make('shift_date')->label($r('date'))->required(),
            TimePicker::make('starts_at')->label($r('starts_at'))->seconds(false)->required(),
            TimePicker::make('ends_at')->label($r('ends_at'))->seconds(false)->required()->helperText($r('ends_help')),
            Select::make('role')->label($r('role'))->placeholder($r('role_default'))->options(array_combine(Role::values(), array_map(fn (Role $x) => $x->label(), Role::cases()))),
            TextInput::make('notes')->label($r('notes'))->maxLength(120),
        ];
    }

    public function addShiftAction(): Action
    {
        return Action::make('addShift')
            ->label(__('staff::staff.roster.add_shift'))
            ->icon(Heroicon::Plus)
            ->schema($this->shiftFields())
            ->fillForm(fn (array $arguments) => ['user_id' => $arguments['user'] ?? null, 'shift_date' => $arguments['date'] ?? $this->week, 'starts_at' => '09:00', 'ends_at' => '17:00'])
            ->action(function (array $data) {
                try {
                    app(RosterService::class)->addShift(User::findOrFail($data['user_id']), $data['shift_date'], $data['starts_at'], $data['ends_at'], $data['role'] ?? null, $data['notes'] ?? null);
                    Notification::make()->success()->title(__('staff::staff.roster.saved'))->send();
                } catch (TimeClockException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public function editShiftAction(): Action
    {
        return Action::make('editShift')
            ->label(__('staff::staff.roster.edit_shift'))
            ->modalHeading(__('staff::staff.roster.edit_shift'))
            ->schema($this->shiftFields())
            ->fillForm(function (array $arguments) {
                $shift = RosterShift::findOrFail($arguments['shift']);

                return ['user_id' => $shift->user_id, 'shift_date' => $shift->shift_date->toDateString(), 'starts_at' => RosterShift::hm($shift->starts_at), 'ends_at' => RosterShift::hm($shift->ends_at), 'role' => $shift->role, 'notes' => $shift->notes];
            })
            ->extraModalFooterActions(fn (Action $action) => [
                $action->makeModalSubmitAction('delete', arguments: ['delete' => true])->label(__('staff::staff.roster.delete'))->color('danger'),
            ])
            ->action(function (array $data, array $arguments) {
                $shift = RosterShift::findOrFail($arguments['shift']);
                $roster = app(RosterService::class);
                if ($arguments['delete'] ?? false) {
                    $roster->deleteShift($shift);
                    Notification::make()->success()->title(__('staff::staff.roster.deleted'))->send();

                    return;
                }
                try {
                    $shift->user_id = $data['user_id'];
                    $roster->updateShift($shift, $data['shift_date'], $data['starts_at'], $data['ends_at'], $data['role'] ?? null, $data['notes'] ?? null);
                    Notification::make()->success()->title(__('staff::staff.roster.saved'))->send();
                } catch (TimeClockException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public function copyLastWeekAction(): Action
    {
        return Action::make('copyLastWeek')
            ->label(__('staff::staff.roster.copy_last_week'))
            ->icon(Heroicon::DocumentDuplicate)
            ->color('gray')
            ->requiresConfirmation()
            ->action(function () {
                $count = app(RosterService::class)->copyWeek($this->weekStart()->subWeek(), $this->weekStart());
                Notification::make()->{$count ? 'success' : 'warning'}()->title($count ? __('staff::staff.roster.copied', ['count' => $count]) : __('staff::staff.roster.nothing_to_copy'))->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addShiftAction(),
            $this->copyLastWeekAction(),
            Action::make('print')->label(__('staff::staff.roster.print'))->icon(Heroicon::Printer)->color('gray')
                ->url(fn () => route('staff.roster.print', ['week' => $this->week]), shouldOpenInNewTab: true),
        ];
    }
}
