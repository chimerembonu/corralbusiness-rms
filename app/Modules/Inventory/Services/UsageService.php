<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\StockTakeStatus;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Models\StockTakeLine;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Theoretical vs actual usage after a stock take (Complete Edition E5.3).
 *
 * For each ingredient counted, over the time since its previous count (or since it was first stocked):
 *   used by recipes (theoretical) = what sold dishes took, minus what voids put back
 *   + recorded waste
 *   + missing = what the count found below what the system expected (negative = more was found)
 *   = actual usage.
 * "Missing" is the unexplained part: over-portioning, unrecorded waste, theft, or a recipe that needs
 * correcting. It is valued at the cost frozen on the count sheet.
 */
class UsageService
{
    /** @return Collection<int, StockTake> completed stock takes of the branch, newest first */
    public function completedTakes(?string $branchId = null): Collection
    {
        return StockTake::query()->forBranch($branchId)->where('status', StockTakeStatus::Completed->value)->latest('completed_at')->limit(50)->get();
    }

    /**
     * @return array{take: StockTake, rows: array<int, array<string, mixed>>, totals: array{recipe_value: int, missing_value: int, waste_value: int}}
     */
    public function report(StockTake $take): array
    {
        $end = $take->completed_at;
        $rows = [];
        foreach ($take->lines()->with('ingredient')->whereNotNull('ingredient_id')->whereNotNull('counted')->orderBy('sort_order')->get() as $line) {
            $ingredient = $line->ingredient;
            if (! $ingredient) {
                continue;
            }
            $start = $this->previousCount($take, $line);
            $moves = StockMovement::query()->where('ingredient_id', $ingredient->id)
                ->when($start, fn ($q) => $q->where('happened_at', '>', $start))
                ->where('happened_at', '<=', $end)
                ->get(['type', 'quantity', 'reference_type', 'reference_id']);
            $sum = fn (StockMovementType $type) => (float) $moves->where('type', $type)->sum(fn ($m) => (float) $m->quantity);

            $recipe = round(-($sum(StockMovementType::Sale) + $sum(StockMovementType::Void)), 3);
            $waste = round(-$sum(StockMovementType::Wastage), 3);
            $difference = (float) $moves->where('type', StockMovementType::StockTake)
                ->filter(fn ($m) => $m->reference_type === $take->getTable() && $m->reference_id === $take->id)->sum(fn ($m) => (float) $m->quantity);
            $missing = round(-$difference, 3);
            $cost = $line->unit_cost->amount;

            if ($recipe == 0.0 && $missing == 0.0 && $waste == 0.0) {
                continue; // nothing happened to it in the period
            }
            $rows[] = [
                'name' => $ingredient->name,
                'unit' => $ingredient->unit->short(),
                'since' => $start?->format('d/m/Y'),
                'purchased' => round($sum(StockMovementType::Purchase), 3),
                'recipe' => $recipe,
                'waste' => $waste,
                'missing' => $missing,
                'actual' => round($recipe + $waste + $missing, 3),
                'missing_pct' => $recipe > 0 ? round($missing / $recipe * 100, 1) : null,
                'recipe_value' => (int) round($recipe * $cost),
                'waste_value' => (int) round($waste * $cost),
                'missing_value' => (int) round($missing * $cost),
            ];
        }
        usort($rows, fn ($a, $b) => $b['missing_value'] <=> $a['missing_value']);

        return [
            'take' => $take,
            'rows' => $rows,
            'totals' => [
                'recipe_value' => (int) array_sum(array_column($rows, 'recipe_value')),
                'waste_value' => (int) array_sum(array_column($rows, 'waste_value')),
                'missing_value' => (int) array_sum(array_column($rows, 'missing_value')),
            ],
        ];
    }

    /** When this ingredient was last counted before this stock take (null = never: since it was first stocked). */
    private function previousCount(StockTake $take, StockTakeLine $line): ?CarbonInterface
    {
        $previous = StockTake::query()->where('branch_id', $take->branch_id)->where('status', StockTakeStatus::Completed->value)
            ->whereKeyNot($take->id)->where('completed_at', '<=', $take->completed_at)
            ->where(fn ($q) => $q->where('completed_at', '<', $take->completed_at)->orWhere('id', '<', $take->id)) // ids are time-ordered
            ->whereHas('lines', fn ($q) => $q->where('ingredient_id', $line->ingredient_id)->whereNotNull('counted'))
            ->latest('completed_at')->latest('id')->first();

        return $previous?->completed_at;
    }
}
