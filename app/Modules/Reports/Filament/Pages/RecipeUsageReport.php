<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Services\UsageService;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Staff\Enums\Permission;
use App\Support\Features\FeatureService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Reports → Recipes vs stock take (Complete Edition E5.3): theoretical (recipe) usage against what a stock
 * take shows was really used, per ingredient. Part of "Recipes & food cost". Stock figures only (no sales),
 * so whoever manages the stock may see it too.
 */
class RecipeUsageReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 51;

    protected static ?string $slug = 'reports/recipe-usage';

    protected string $view = 'reports::filament.recipe-usage';

    #[Url]
    public string $take = '';

    public static function canAccess(): bool
    {
        return (auth()->user()?->canAny([Permission::ReportsView->value, Permission::InventoryManage->value]) ?? false)
            && app(FeatureService::class)->isOn('recipes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('reports::reports.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports::reports.usage.title');
    }

    public function getTitle(): string
    {
        return __('reports::reports.usage.title');
    }

    public function getSubheading(): ?string
    {
        return __('reports::reports.usage.help');
    }

    public function mount(): void
    {
        if ($this->take === '' || ! $this->selected()) {
            $this->take = (string) app(UsageService::class)->completedTakes()->first()?->id;
        }
    }

    /** @return array<string, string> completed stock takes of this branch, newest first */
    public function takes(): array
    {
        return app(UsageService::class)->completedTakes()
            ->mapWithKeys(fn (StockTake $t) => [$t->id => $t->displayNumber().' · '.__('reports::reports.usage.counted_on', ['date' => $t->completed_at->format('d/m/Y H:i')])])->all();
    }

    public function selected(): ?StockTake
    {
        return $this->take === '' ? null : app(UsageService::class)->completedTakes()->firstWhere('id', $this->take);
    }

    public function data(): ?array
    {
        $take = $this->selected();

        return $take ? app(UsageService::class)->report($take) : null;
    }

    protected function getHeaderActions(): array
    {
        $canExport = auth()->user()->can(Permission::ReportsExport->value) || auth()->user()->can(Permission::InventoryManage->value);

        return [
            Action::make('excel')->label(__('reports::reports.export.excel'))->icon(Heroicon::ArrowDownTray)->color('gray')
                ->visible(fn () => $canExport && $this->selected())->action(fn () => $this->document()->excel()),
            Action::make('pdf')->label(__('reports::reports.export.pdf'))->icon(Heroicon::DocumentText)->color('gray')
                ->visible(fn () => $canExport && $this->selected())->action(fn () => $this->document()->pdf()),
        ];
    }

    private function document(): ReportDocument
    {
        $d = $this->data();
        $c = fn (string $k) => __('reports::reports.usage.columns.'.$k);
        $date = $d['take']->completed_at->toDateString();
        $rows = array_map(fn ($r) => [$r['name'], $r['since'] ?? __('reports::reports.usage.first_count'), $r['unit'], $r['purchased'], $r['recipe'], $r['waste'], $r['missing'], $r['actual'],
            $r['missing_pct'] === null ? '—' : $r['missing_pct'].'%', $r['missing_value']], $d['rows']);

        return (new ReportDocument(__('reports::reports.usage.title').' '.$d['take']->displayNumber(), $date, $date))
            ->section($d['take']->displayNumber(), [$c('ingredient'), $c('since'), '', $c('purchased'), $c('recipe'), $c('waste'), $c('missing'), $c('actual'), $c('missing_pct'), $c('missing_value')],
                $rows, [9], [__('reports::reports.total'), '', '', '', '', '', '', '', '', $d['totals']['missing_value']]);
    }
}
