<?php

namespace App\Modules\Reports\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Menu\Models\Item;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Models\OrderItem;
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
        $orderIds = $this->reports->salesOrders($from, $to, $branchId)->pluck('id');
        $lines = OrderItem::query()->withoutGlobalScopes()->whereIn('order_id', $orderIds)->where('status', '!=', OrderItemStatus::Voided->value)
            ->whereNull('deleted_at') // removed lines were never sold; withoutGlobalScopes() also drops the soft-delete filter (test report H7)
            ->get();
        $costs = $this->lineCosts($lines->pluck('id'));
        $categories = Item::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', $lines->pluck('item_id')->filter()->unique())->with('category')->get()
            ->mapWithKeys(fn (Item $i) => [$i->id => $i->category?->name ?? __('reports::reports.other')])->all();

        $row = function (string $name, string $category, BaseCollection $group) use ($costs) {
            $net = (int) $group->sum(fn (OrderItem $l) => $l->line_total->amount - $l->tax_total->amount);
            $costed = $group->filter(fn (OrderItem $l) => isset($costs[$l->id]));
            $costedNet = (int) $costed->sum(fn (OrderItem $l) => $l->line_total->amount - $l->tax_total->amount);
            $cost = (int) $costed->sum(fn (OrderItem $l) => $costs[$l->id]);

            return [
                'name' => $name,
                'category' => $category,
                'quantity' => (int) $group->sum('quantity'),
                'net' => $net,
                'costed_net' => $costedNet,
                'cost' => $cost,
                'margin' => $costedNet - $cost,
                'food_cost_pct' => $costedNet > 0 ? round($cost / $costedNet * 100, 1) : null,
                'costed' => $costed->isEmpty() ? 'none' : ($costed->count() === $group->count() ? 'all' : 'some'),
            ];
        };

        $dishes = $lines->groupBy(fn (OrderItem $l) => $l->displayName())
            ->map(fn (BaseCollection $group, string $name) => $row($name, $categories[$group->first()->item_id] ?? __('reports::reports.other'), $group))
            ->sortByDesc('net')->values()->all();
        $byCategory = $lines->groupBy(fn (OrderItem $l) => $categories[$l->item_id] ?? __('reports::reports.other'))
            ->map(fn (BaseCollection $group, string $category) => $row($category, $category, $group))
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
