<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\Route;
use App\Modules\Kitchen\Models\Station;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Which station cooks what. Rules, most specific first:
 *   item rule for the branch → item rule for the tenant → category rule for the branch →
 *   category rule for the tenant → the branch's default station → null (no kitchen).
 */
class RoutingService
{
    public function __construct(private readonly TenantContext $context) {}

    /** Set (or clear with null) the station for a category or an item. */
    public function route(string $type, string $targetId, ?string $stationId, ?string $branchId = null): ?Route
    {
        $query = Route::query()->where('routable_type', $type)->where('routable_id', $targetId)->where('branch_id', $branchId);

        if ($stationId === null) {
            $query->get()->each->delete();

            return null;
        }

        $route = $query->first() ?? new Route(['routable_type' => $type, 'routable_id' => $targetId, 'branch_id' => $branchId]);
        $route->station_id = $stationId;
        $route->save();

        return $route;
    }

    /** All rules that apply at a branch, keyed "type:id" → station id (branch rules win). */
    public function map(?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        $map = [];

        Route::query()->forBranchOrTenant($branchId)->orderByRaw('branch_id is null desc')->get()
            ->each(function (Route $r) use (&$map) {
                $map[$r->routable_type.':'.$r->routable_id] = $r->station_id;
            });

        return $map;
    }

    /** The station for a menu item (with its category), or null when there is no kitchen for it. */
    public function stationFor(?string $itemId, ?string $categoryId, ?string $branchId = null): ?Station
    {
        $branchId ??= $this->context->branchId();
        $map = $this->map($branchId);
        $stations = $this->stations($branchId);

        $stationId = $map[Route::ITEM.':'.$itemId] ?? $map[Route::CATEGORY.':'.$categoryId] ?? null;

        return ($stationId ? $stations->firstWhere('id', $stationId) : null)
            ?? $stations->firstWhere('is_default', true)
            ?? null;
    }

    /**
     * Group order lines by station: [station id or '' => Collection<OrderItem>]. Lines whose
     * menu item is unknown (deleted) go to the default station.
     *
     * @param  Collection<int, OrderItem>  $lines
     * @return Collection<string, Collection<int, OrderItem>>
     */
    public function groupByStation(Collection $lines, ?string $branchId = null): Collection
    {
        $lines->each(fn (OrderItem $l) => $l->loadMissing('menuItem'));

        return $lines->groupBy(fn (OrderItem $line) => $this->stationFor($line->item_id, $line->menuItem?->category_id, $branchId)?->id ?? '');
    }

    /** @return Collection<int, Station> active stations at the branch, in order */
    public function stations(?string $branchId = null): Collection
    {
        return Station::query()->forBranch($branchId ?? $this->context->branchId())->active()->ordered()->get();
    }
}
