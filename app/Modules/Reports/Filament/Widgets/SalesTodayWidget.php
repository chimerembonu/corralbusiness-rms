<?php

namespace App\Modules\Reports\Filament\Widgets;

use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Permission;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard: today's sales against yesterday. */
class SalesTodayWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::ReportsView->value) ?? false;
    }

    protected function getStats(): array
    {
        $r = app(ReportService::class);
        $today = $r->summary(now()->toDateString(), now()->toDateString());
        $yesterday = $r->summary(now()->subDay()->toDateString(), now()->subDay()->toDateString());
        $t = fn (string $k) => __('reports::reports.dashboard.'.$k);
        $vs = fn (int $now, int $then) => $t('yesterday').': '.money($then);

        return [
            Stat::make($t('gross').' · '.$t('today'), money($today['gross_sales']))->description($vs($today['gross_sales'], $yesterday['gross_sales']))
                ->color($today['gross_sales'] >= $yesterday['gross_sales'] ? 'success' : 'warning'),
            Stat::make(__('reports::reports.sales.kpi.orders'), (string) $today['orders'])->description($t('yesterday').': '.$yesterday['orders'].' '.$t('orders')),
            Stat::make(__('reports::reports.sales.kpi.average_order'), money($today['average_order']))->description($t('yesterday').': '.money($yesterday['average_order'])),
        ];
    }
}
