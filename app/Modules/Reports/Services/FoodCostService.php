<?php

namespace App\Modules\Reports\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Menu\Models\Item;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Food cost and margin per dish and category (Complete Edition E5.3).
 *
 * Sales are the same as the sales reports (net of tax and discounts). The cost of a dish is what its recipe
 * actually took off the shelf when it was sold, at the ingredient's cost at that moment (the Sale stock
 * movements of each order line) — so a recipe or price changed later never rewrites the past. Lines with no
 * recorded ingredient use (sold before recipes were switched on, or a dish without a recipe) are counted in
 * sales but kept out of the food cost %, and shown as "no cost yet".
 */
class FoodCostService
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * @return array{dishes: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>,
     *     totals: array{net: int, costed_net: int, cost: int, margin: int, food_cost_pct: ?float, uncosted_net: int, uncosted_dishes: int}}
     */
    public function report(string $from, string $to, ?string $branchId = null): array
    {
        // Lines are read a page at a time and added up, never all loaded at once (test report 9 Nov 2026, H10).
        $blank = ['item_id' => null, 'quantity' => 0, 'lines' => 0, 'net' => 0, 'costed_lines' => 0, 'costed_net' => 0, 'cost' => 0];
        $perDish = [];
        $perItem = [];
        $add = static function (array &$groups, string $key, $line, int $net, ?int $cost) use ($blank): void {
            $groups[$key] ??= ['item_id' => $line->item_id] + $blank;
            $groups[$key]['quantity'] += $line->quantity;
            $groups[$key]['lines']++;
            $groups[$key]['net'] += $net;
            if ($cost !== null) {
                $groups[$key]['costed_lines']++;
                $groups[$key]['costed_net'] += $net;
                $groups[$key]['cost'] += $cost;
            }
        };
        foreach ($this->reports->soldLinePages($from, $to, $branchId) as $lines) {
            $costs = $this->lineCosts($lines->pluck('id'));
            foreach ($lines as $l) {
                $net = $l->line_total - $l->tax_total;
                $add($perDish, $l->name, $l, $net, $costs[$l->id] ?? null);
                $add($perItem, (string) $l->item_id, $l, $net, $costs[$l->id] ?? null);
            }
        }
        $categories = Item::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', array_filter(array_keys($perItem)))->with('category')->get()
            ->mapWithKeys(fn (Item $i) => [$i->id => $i->category?->name ?? __('reports::reports.other')])->all();
        $perCategory = [];
        foreach ($perItem as $g) {
            $category = $categories[$g['item_id']] ?? __('reports::reports.other');
            $perCategory[$category] ??= $blank;
            foreach (['quantity', 'lines', 'net', 'costed_lines', 'costed_net', 'cost'] as $f) {
                $perCategory[$category][$f] += $g[$f];
            }
        }

        $row = fn (string $name, string $category, array $g) => [
            'name' => $name,
            'category' => $category,
            'quantity' => $g['quantity'],
            'net' => $g['net'],
            'costed_net' => $g['costed_net'],
            'cost' => $g['cost'],
            'margin' => $g['costed_net'] - $g['cost'],
            'food_cost_pct' => $g['costed_net'] > 0 ? round($g['cost'] / $g['costed_net'] * 100, 1) : null,
            'costed' => $g['costed_lines'] === 0 ? 'none' : ($g['costed_lines'] === $g['lines'] ? 'all' : 'some'),
        ];

        $dishes = collect($perDish)->map(fn (array $g, $name) => $row((string) $name, $categories[$g['item_id']] ?? __('reports::reports.other'), $g))
            ->sortByDesc('net')->values()->all();
        $byCategory = collect($perCategory)->map(fn (array $g, $category) => $row((string) $category, (string) $category, $g))
            ->sortByDesc('net')->values()->all();

        $net = (int) collect($dishes)->sum('net');
        $costedNet = (int) collect($dishes)->sum('costed_net');
        $cost = (int) collect($dishes)->sum('cost');

        return [
            'dishes' => $dishes,
            'categories' => $byCategory,
            'totals' => [
                'net' => $net,
                'costed_net' => $costedNet,
                'cost' => $cost,
                'margin' => $costedNet - $cost,
                'food_cost_pct' => $costedNet > 0 ? round($cost / $costedNet * 100, 1) : null,
                'uncosted_net' => $net - $costedNet,
                'uncosted_dishes' => collect($dishes)->where('costed', 'none')->count(),
            ],
        ];
    }

    /**
     * What each order line took off the shelf, at cost (pesewas): its ingredient Sale movements.
     *
     * @return array<string, int> order line id => cost
     */
    private function lineCosts(BaseCollection $lineIds): array
    {
        $costs = [];
        foreach ($lineIds->chunk(500) as $chunk) {
            StockMovement::query()->withoutGlobalScopes()->whereIn('order_item_id', $chunk)
                ->where('type', StockMovementType::Sale->value)->whereNotNull('ingredient_id')
                ->get(['order_item_id', 'quantity', 'unit_cost'])
                ->each(function (StockMovement $m) use (&$costs) {
                    $costs[$m->order_item_id] = ($costs[$m->order_item_id] ?? 0) + (int) round(abs((float) $m->quantity) * ($m->unit_cost?->amount ?? 0));
                });
        }

        return $costs;
    }
}
