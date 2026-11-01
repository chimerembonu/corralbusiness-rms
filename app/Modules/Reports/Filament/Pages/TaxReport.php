<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Pages\Concerns\HasDateRange;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Reports\Services\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class TaxReport extends Page
{
    use HasDateRange;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'reports/tax';

    protected string $view = 'reports::filament.tax';

    public static function getNavigationLabel(): string
    {
        return __('reports::reports.tax.title');
    }

    public function getTitle(): string
    {
        return __('reports::reports.tax.title');
    }

    public function data(): array
    {
        [$from, $to] = $this->range();

        return app(ReportService::class)->tax($from, $to);
    }

    protected function document(): ReportDocument
    {
        [$from, $to] = $this->range();
        $d = $this->data();
        $c = fn (string $k) => __('reports::reports.tax.columns.'.$k);
        $k = fn (string $key) => __('reports::reports.tax.kpi.'.$key);

        return (new ReportDocument(__('reports::reports.tax.title'), $from, $to))
            ->section(__('reports::reports.tax.summary'), [$k('gross'), $k('taxable_net'), $k('exempt_net'), $k('tax_total')], [[$d['gross'], $d['taxable_net'], $d['exempt_net'], $d['tax_total']]], [0, 1, 2, 3])
            ->section(__('reports::reports.tax.components'), [$c('component'), $c('rate'), $c('taxable_net'), $c('tax')], array_map(fn ($r) => [$r['name'], $r['rate'], $r['taxable_net'], $r['tax']], $d['components']), [2, 3], ['', __('reports::reports.total'), $d['taxable_net'], $d['tax_total']]);
    }
}
