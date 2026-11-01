<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Pages\Concerns\HasDateRange;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Reports\Services\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class VoidsReport extends Page
{
    use HasDateRange;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'reports/voids';

    protected string $view = 'reports::filament.voids';

    public static function getNavigationLabel(): string
    {
        return __('reports::reports.voids.title');
    }

    public function getTitle(): string
    {
        return __('reports::reports.voids.title');
    }

    public function data(): array
    {
        [$from, $to] = $this->range();

        return app(ReportService::class)->voidsAndDiscounts($from, $to);
    }

    protected function document(): ReportDocument
    {
        [$from, $to] = $this->range();
        $d = $this->data();
        $c = fn (string $k) => __('reports::reports.voids.columns.'.$k);
        $rows = fn (string $key) => array_map(fn ($r) => [$r['when'], $r['order'], $r['detail'], $r['value'], $r['by']], $d[$key]);
        $total = fn (string $key) => ['', '', __('reports::reports.total'), $d['totals'][$key], ''];

        return (new ReportDocument(__('reports::reports.voids.title'), $from, $to))
            ->section(__('reports::reports.voids.voided_orders'), [$c('when'), $c('order'), $c('detail'), $c('value'), $c('by')], $rows('voided_orders'), [3], $total('voided_orders'))
            ->section(__('reports::reports.voids.voided_lines'), [$c('when'), $c('order'), $c('detail'), $c('value'), $c('by')], $rows('voided_lines'), [3], $total('voided_lines'))
            ->section(__('reports::reports.voids.discounts'), [$c('when'), $c('order'), $c('detail'), $c('value'), $c('by')], $rows('discounts'), [3], $total('discounts'))
            ->section(__('reports::reports.voids.refunds'), [$c('when'), $c('order'), $c('detail'), $c('value'), $c('by')], $rows('refunds'), [3], $total('refunds'));
    }
}
