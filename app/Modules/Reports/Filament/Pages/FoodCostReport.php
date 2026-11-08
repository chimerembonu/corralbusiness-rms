<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Reports\Filament\Pages\Concerns\HasDateRange;
use App\Modules\Reports\Services\FoodCostService;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Staff\Enums\Permission;
use App\Support\Features\FeatureService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Reports → Food cost & margin (Complete Edition E5.3). Part of "Recipes & food cost". */
class FoodCostReport extends Page
{
    use HasDateRange;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'reports/food-cost';

    protected string $view = 'reports::filament.food-cost';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can(Permission::ReportsView->value) ?? false) && app(FeatureService::class)->isOn('recipes');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports::reports.food_cost.title');
    }

    public function getTitle(): string
    {
        return __('reports::reports.food_cost.title');
    }

    public function getSubheading(): ?string
    {
        return __('reports::reports.food_cost.help');
    }

    public function data(): array
    {
        [$from, $to] = $this->range();

        return app(FoodCostService::class)->report($from, $to);
    }

    protected function document(): ReportDocument
    {
        [$from, $to] = $this->range();
        $d = $this->data();
        $c = fn (string $k) => __('reports::reports.food_cost.columns.'.$k);
        $pct = fn (?float $p) => $p === null ? '—' : $p.'%';
        $headings = [$c('dish'), $c('category'), $c('quantity'), $c('net'), $c('cost'), $c('margin'), $c('food_cost_pct')];
        $rows = fn (array $list) => array_map(fn ($r) => [$r['name'], $r['category'], $r['quantity'], $r['net'], $r['cost'], $r['margin'], $pct($r['food_cost_pct'])], $list);
        $t = $d['totals'];

        return (new ReportDocument(__('reports::reports.food_cost.title'), $from, $to))
            ->section(__('reports::reports.food_cost.by_category'), array_merge([$c('category')], array_slice($headings, 2)),
                array_map(fn ($r) => [$r['name'], $r['quantity'], $r['net'], $r['cost'], $r['margin'], $pct($r['food_cost_pct'])], $d['categories']), [2, 3, 4],
                [__('reports::reports.total'), '', $t['net'], $t['cost'], $t['margin'], $pct($t['food_cost_pct'])])
            ->section(__('reports::reports.food_cost.by_dish'), $headings, $rows($d['dishes']), [3, 4, 5]);
    }
}
