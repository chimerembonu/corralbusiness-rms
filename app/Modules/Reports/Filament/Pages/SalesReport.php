<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Pages\Concerns\HasDateRange;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Reports\Services\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Back office → Reports → Sales: summary, by day, by hour, by order type, by payment method, by staff. */
class SalesReport extends Page
{
    use HasDateRange;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'reports/sales';

    protected string $view = 'reports::filament.sales';

    public static function getNavigationLabel(): string
    {
        return __('reports::reports.sales.title');
    }

    public function getTitle(): string
    {
        return __('reports::reports.sales.title');
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        [$from, $to] = $this->range();
        $r = app(ReportService::class);

        return [
            'summary' => $r->summary($from, $to),
            'by_day' => $r->byDay($from, $to),
            'by_hour' => $r->byHour($from, $to),
            'by_type' => $r->byType($from, $to),
            'by_payment' => $r->byPaymentMethod($from, $to),
            'by_staff' => $r->byStaff($from, $to),
        ];
    }

    protected function document(): ReportDocument
    {
        [$from, $to] = $this->range();
        $d = $this->data();
        $s = $d['summary'];
        $t = fn (string $k) => __('reports::reports.sales.'.$k);
        $c = fn (string $k) => __('reports::reports.sales.columns.'.$k);
        $k = fn (string $key) => __('reports::reports.sales.kpi.'.$key);

        return (new ReportDocument($t('title'), $from, $to))
            ->section($t('summary'), [$k('gross_sales'), $k('net_sales'), $k('tax_total'), $k('orders'), $k('average_order'), $k('covers'), $k('discount_total'), $k('service_charge_total'), $k('delivery_fee_total'), $k('tip_total'), $k('refund_total'), $k('orders_void'), $k('void_value')],
                [[$s['gross_sales'], $s['net_sales'], $s['tax_total'], $s['orders'], $s['average_order'], $s['covers'], $s['discount_total'], $s['service_charge_total'], $s['delivery_fee_total'], $s['tip_total'], $s['refund_total'], $s['orders_void'], $s['void_value']]], [0, 1, 2, 4, 6, 7, 8, 9, 10, 12])
            ->section($t('by_day'), [$c('date'), $c('orders'), $c('gross'), $c('net'), $c('tax'), $c('discounts')], array_map(fn ($r) => [$r['date'], $r['orders'], $r['gross'], $r['net'], $r['tax'], $r['discounts']], $d['by_day']), [2, 3, 4, 5],
                [__('reports::reports.total'), $s['orders'], $s['gross_sales'], $s['net_sales'], $s['tax_total'], $s['discount_total']])
            ->section($t('by_hour'), [$c('hour'), $c('orders'), $c('gross')], array_map(fn ($r) => [$r['label'], $r['orders'], $r['gross']], $d['by_hour']), [2])
            ->section($t('by_type'), [$c('type'), $c('orders'), $c('gross'), $c('average')], array_map(fn ($r) => [$r['label'], $r['orders'], $r['gross'], $r['average']], $d['by_type']), [2, 3])
            ->section($t('by_payment'), [$c('method'), $c('count'), $c('total'), $c('tips'), $c('refunded')], array_map(fn ($r) => [$r['label'], $r['count'], $r['total'], $r['tips'], $r['refunded']], $d['by_payment']), [2, 3, 4])
            ->section($t('by_staff'), [$c('staff'), $c('role'), $c('orders'), $c('sales'), $c('average'), $c('voids'), $c('payments'), $c('taken'), $c('tips')], array_map(fn ($r) => [$r['name'], $r['role'], $r['orders'], $r['sales'], $r['average'], $r['voids'], $r['payments'], $r['taken'], $r['tips']], $d['by_staff']), [3, 4, 7, 8]);
    }
}
