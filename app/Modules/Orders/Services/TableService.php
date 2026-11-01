<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderTransferred;
use App\Modules\Orders\Events\TablesMerged;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Exceptions\TableUnavailableException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Floor plan: table layout, statuses, moving orders between tables and joining tables. */
class TableService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly OrderService $orders,
    ) {}

    /**
     * The floor plan for a branch: every active section with its active tables (statuses loaded).
     * Tables without a section come last under a null section. Unplaced tables get a temporary
     * grid position (not saved) so they still show up.
     *
     * @return Collection<int, array{section: ?FloorSection, tables: Collection<int, DiningTable>}>
     */
    public function floorPlan(?string $branchId = null): Collection
    {
        $branchId ??= $this->context->branchId();

        $sections = FloorSection::query()->forBranch($branchId)->active()->ordered()->get();
        $tables = DiningTable::query()->forBranch($branchId)->active()->ordered()
            ->with(['currentOrder', 'mergedInto', 'mergedTables'])
            ->get();

        $groups = $sections->map(fn (FloorSection $section) => [
            'section' => $section,
            'tables' => $this->autoPlace($tables->where('section_id', $section->id)->values()),
        ]);

        $loose = $tables->filter(fn (DiningTable $t) => $t->section_id === null || ! $sections->contains('id', $t->section_id))->values();
        if ($loose->isNotEmpty()) {
            $groups->push(['section' => null, 'tables' => $this->autoPlace($loose)]);
        }

        return $groups->values();
    }

    /** Save new grid positions: [['id' => …, 'x' => …, 'y' => …], …]. Values are clamped to the grid. */
    public function arrange(array $positions): int
    {
        $moved = 0;

        foreach ($positions as $position) {
            $table = DiningTable::query()->find($position['id'] ?? null);
            if (! $table) {
                continue;
            }

            $table->pos_x = max(0, min(DiningTable::COLS - $table->width, (int) $position['x']));
            $table->pos_y = max(0, min(DiningTable::ROWS - $table->height, (int) $position['y']));
            if ($table->isDirty()) {
                $table->save();
                $moved++;
            }
        }

        return $moved;
    }

    /** Move a dine-in order to a free table. */
    public function transfer(Order $order, DiningTable $to, ?User $by = null): Order
    {
        $this->assertActive($order);

        if ($order->type !== OrderType::DineIn) {
            throw new TableUnavailableException(__('orders::orders.errors.not_dine_in'));
        }
        if ($order->table_id === $to->id) {
            throw new TableUnavailableException(__('orders::orders.errors.same_table'));
        }
        $this->assertFree($to);

        return DB::transaction(function () use ($order, $to, $by) {
            $fromId = $order->table_id;

            // Tables that were joined onto the old table follow the order.
            DiningTable::query()->where('merged_into_id', $fromId)->update(['merged_into_id' => $to->id]);

            $order->table_id = $to->id;
            $order->save();
            $order->load('table');

            OrderTransferred::dispatch($order, $fromId, $to->id, $to->name, $by?->id);

            return $order;
        });
    }

    /**
     * Join table $from onto table $into. The order on $into (or, if $into is free, the order moved
     * from $from) becomes the single order for both; lines from a second open order are moved across.
     */
    public function merge(DiningTable $from, DiningTable $into, ?User $by = null): Order
    {
        if ($from->id === $into->id) {
            throw new TableUnavailableException(__('orders::orders.errors.same_table'));
        }
        foreach ([$from, $into] as $table) {
            if (! $table->is_active) {
                throw new TableUnavailableException(__('orders::orders.errors.table_inactive', ['table' => $table->name]));
            }
            if ($table->isMerged()) {
                throw new TableUnavailableException(__('orders::orders.errors.table_merged', ['table' => $table->name]));
            }
        }

        $fromOrder = $from->currentOrder()->first();
        $intoOrder = $into->currentOrder()->first();

        if (! $fromOrder && ! $intoOrder) {
            throw new TableUnavailableException(__('orders::orders.errors.nothing_to_merge'));
        }

        return DB::transaction(function () use ($from, $into, $fromOrder, $intoOrder, $by) {
            $sourceOrderId = null;

            if ($intoOrder && $fromOrder) {
                // Two parties become one: move every line, add the covers, close the source order.
                $fromOrder->items()->update(['order_id' => $intoOrder->id]);
                $fromOrder->forceFill(['status' => OrderStatus::Merged, 'merged_into_id' => $intoOrder->id])->save();
                $intoOrder->covers += $fromOrder->covers;
                $intoOrder->save();
                $sourceOrderId = $fromOrder->id;
                $this->orders->recalculate($fromOrder);
                $this->orders->recalculate($intoOrder);
            } elseif ($fromOrder) {
                // Only the joining table has an order: it moves to the main table.
                $fromOrder->table_id = $into->id;
                $fromOrder->save();
                $intoOrder = $fromOrder;
            }

            // Tables already joined onto $from now hang off $into, then $from itself.
            DiningTable::query()->where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);
            $from->merged_into_id = $into->id;
            $from->save();

            $intoOrder = $intoOrder->fresh(['table', 'items']);

            TablesMerged::dispatch($intoOrder, $from->id, $into->id, $sourceOrderId, $by?->id);

            return $intoOrder;
        });
    }

    /** Release every table joined onto the given order's table (called when the order closes). */
    public function releaseMergedTables(Order $order): int
    {
        if (! $order->table_id) {
            return 0;
        }

        return DiningTable::query()->where('merged_into_id', $order->table_id)->update(['merged_into_id' => null]);
    }

    /** A table can take a new order only when it is active, free and not joined onto another table. */
    public function assertFree(DiningTable $table): void
    {
        if (! $table->is_active) {
            throw new TableUnavailableException(__('orders::orders.errors.table_inactive', ['table' => $table->name]));
        }
        if ($table->isMerged()) {
            throw new TableUnavailableException(__('orders::orders.errors.table_merged', ['table' => $table->name]));
        }
        if ($table->isOccupied()) {
            throw new OrderLockedException(__('orders::orders.errors.table_occupied', ['table' => $table->name]));
        }
    }

    private function assertActive(Order $order): void
    {
        if (! $order->isActive()) {
            throw new OrderLockedException(__('orders::orders.errors.order_closed', ['status' => $order->status->label()]));
        }
    }

    /** Give unplaced tables a temporary spot in reading order, skipping cells that placed tables use. */
    private function autoPlace(Collection $tables): Collection
    {
        $taken = [];
        foreach ($tables as $table) {
            if ($table->isPlaced()) {
                for ($x = $table->pos_x; $x < $table->pos_x + $table->width; $x++) {
                    for ($y = $table->pos_y; $y < $table->pos_y + $table->height; $y++) {
                        $taken["$x,$y"] = true;
                    }
                }
            }
        }

        foreach ($tables as $table) {
            if ($table->isPlaced()) {
                continue;
            }
            for ($y = 0; $y <= DiningTable::ROWS - $table->height; $y++) {
                for ($x = 0; $x <= DiningTable::COLS - $table->width; $x++) {
                    $fits = true;
                    for ($dx = 0; $dx < $table->width && $fits; $dx++) {
                        for ($dy = 0; $dy < $table->height; $dy++) {
                            if (isset($taken[($x + $dx).','.($y + $dy)])) {
                                $fits = false;
                                break;
                            }
                        }
                    }
                    if ($fits) {
                        $table->pos_x = $x;
                        $table->pos_y = $y;
                        for ($dx = 0; $dx < $table->width; $dx++) {
                            for ($dy = 0; $dy < $table->height; $dy++) {
                                $taken[($x + $dx).','.($y + $dy)] = true;
                            }
                        }

                        continue 3;
                    }
                }
            }
        }

        return $tables;
    }
}
