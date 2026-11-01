<?php

namespace App\Modules\Reports\Filament\Widgets;

use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Permission;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

/** Dashboard: gross sales per day for the last 14 days. */
class SalesChartWidget extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::ReportsView->value) ?? false;
    }

    public function getHeading(): ?string
    {
        return __('reports::reports.dashboard.last_14_days');
    }

    protected function getData(): array
    {
        $rows = app(ReportService::class)->byDay(now()->subDays(13)->toDateString(), now()->toDateString());

        return [
            'datasets' => [[
                'label' => __('reports::reports.dashboard.gross').' ('.config('rms.currency').')',
                'data' => array_map(fn ($r) => round($r['gross'] / 100, 2), $rows),
                'borderColor' => '#10b981',
                'backgroundColor' => 'rgba(16,185,129,.15)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_map(fn ($r) => Carbon::parse($r['date'])->format('D j'), $rows),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
