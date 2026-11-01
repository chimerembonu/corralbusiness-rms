<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Pages\Concerns\HasDateRange;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Reports\Services\ReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ItemsReport extends Page
{
    use HasDateRange;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'reports/items';

    protected string $view = 'reports::filament.items';

    public static function getNavigationLabel(): string
    {
        return __('reports::reports.items.title');
    }

    public function getTitle(): string
    {
        return __('reports::reports.items.title');
    }

    public function data(): array
    {
        [$from, $to] = $this->range();
        $r = app(ReportService::class);

        return ['by_item' => $r->byItem($from, $to), 'by_category' => $r->byCategory($from, $to)];
    }

    protected function document(): ReportDocument
    {
        [$from, $to] = $this->range();
        $d = $this->data();
        $c = fn (string $k) => __('reports::reports.items.columns.'.$k);

        return (new ReportDocument(__('reports::reports.items.title'), $from, $to))
            ->section(__('reports::reports.items.by_category'), [$c('category'), $c('quantity'), $c('gross'), $c('share')], array_map(fn ($r) => [$r['category'], $r['quantity'], $r['gross'], $r['share']], $d['by_category']), [2])
            ->section(__('reports::reports.items.by_item'), [$c('item'), $c('category'), $c('quantity'), $c('gross'), $c('net'), $c('share')], array_map(fn ($r) => [$r['name'], $r['category'], $r['quantity'], $r['gross'], $r['net'], $r['share']], $d['by_item']), [3, 4]);
    }
}
