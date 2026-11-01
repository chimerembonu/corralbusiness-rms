<?php

namespace App\Modules\Reports\Filament\Pages\Concerns;

use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Staff\Enums\Permission;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Date range + presets + Excel/PDF export, shared by every report page. */
trait HasDateRange
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ReportsView->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('reports::reports.navigation_group');
    }

    public function mount(): void
    {
        if ($this->from === '' || $this->to === '') {
            $this->preset('today');
        }
    }

    public function preset(string $which): void
    {
        $now = CarbonImmutable::now();
        [$from, $to] = match ($which) {
            'yesterday' => [$now->subDay(), $now->subDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            default => [$now, $now],
        };
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /** Guard against an empty or reversed range typed by hand. */
    protected function range(): array
    {
        $from = $this->from ?: now()->toDateString();
        $to = $this->to ?: $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    abstract protected function document(): ReportDocument;

    protected function getHeaderActions(): array
    {
        $canExport = auth()->user()->can(Permission::ReportsExport->value);

        return [
            Action::make('excel')->label(__('reports::reports.export.excel'))->icon(Heroicon::ArrowDownTray)->color('gray')->visible($canExport)->action(fn () => $this->document()->excel()),
            Action::make('pdf')->label(__('reports::reports.export.pdf'))->icon(Heroicon::DocumentText)->color('gray')->visible($canExport)->action(fn () => $this->document()->pdf()),
        ];
    }
}
