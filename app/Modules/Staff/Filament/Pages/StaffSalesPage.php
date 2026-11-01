<?php

namespace App\Modules\Staff\Filament\Pages;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exports\StaffSalesExport;
use App\Modules\Staff\Services\RosterService;
use App\Modules\Staff\Services\StaffSalesService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/** Back office → Staff → Sales per staff. */
class StaffSalesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'staff-sales';

    protected string $view = 'staff::filament.sales';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function getNavigationGroup(): ?string
    {
        return __('staff::staff.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff::staff.sales.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ReportsView->value) ?? false;
    }

    public function getTitle(): string
    {
        return __('staff::staff.sales.title');
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
            'today' => [$now, $now],
            'last_week' => [RosterService::weekStart()->subWeek(), RosterService::weekStart()->subDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            default => [RosterService::weekStart(), RosterService::weekStart()->addDays(6)],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function rows(): Collection
    {
        return app(StaffSalesService::class)->between($this->from, $this->to);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label(__('staff::staff.sales.export'))->icon(Heroicon::ArrowDownTray)->color('gray')
                ->action(fn () => Excel::download(new StaffSalesExport($this->from, $this->to), "staff-sales-{$this->from}-to-{$this->to}.xlsx")),
        ];
    }
}
