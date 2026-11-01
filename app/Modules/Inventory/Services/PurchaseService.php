<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\PurchaseReceived;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Inventory\Models\PurchaseLine;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Settings\Services\CounterService;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Purchase orders → goods received. Receiving posts the stock and updates each ingredient's last cost. */
class PurchaseService
{
    public function __construct(private readonly StockService $stock, private readonly CounterService $counters, private readonly TenantContext $context) {}

    /**
     * @param  array<int, array{ingredient_id: string, quantity: float|string, unit_cost?: int|Money|null}>  $lines
     */
    public function create(?Supplier $supplier, array $lines, ?User $by = null, ?string $notes = null, ?string $expectedOn = null, ?string $branchId = null): Purchase
    {
        $branchId ??= $this->context->branchId();

        return DB::transaction(function () use ($supplier, $lines, $by, $notes, $expectedOn, $branchId) {
            $purchase = new Purchase([
                'number' => $this->counters->next('purchases', null, $branchId),
                'supplier_id' => $supplier?->id,
                'created_by_id' => $by?->id,
                'expected_on' => $expectedOn,
                'notes' => filled($notes) ? trim($notes) : null,
            ]);
            $purchase->branch_id = $branchId;
            $purchase->save();
            $this->replaceLines($purchase, $lines);

            return $purchase;
        });
    }

    /** @param  array<int, array{ingredient_id: string, quantity: float|string, unit_cost?: int|Money|null}>  $lines */
    public function replaceLines(Purchase $purchase, array $lines): Purchase
    {
        $this->assertOpen($purchase);
        if ($lines === []) {
            throw new InventoryException(__('inventory::inventory.errors.lines_required'));
        }

        DB::transaction(function () use ($purchase, $lines) {
            $purchase->lines()->delete();
            $total = Money::zero();
            foreach (array_values($lines) as $i => $line) {
                $ingredient = Ingredient::query()->findOrFail($line['ingredient_id']);
                $quantity = (float) $line['quantity'];
                if ($quantity <= 0) {
                    throw new InventoryException(__('inventory::inventory.errors.quantity_required'));
                }
                $cost = $line['unit_cost'] ?? null;
                $unitCost = $cost instanceof Money ? $cost : ($cost === null || $cost === '' ? $ingredient->cost_per_unit : Money::of((int) $cost));
                $lineTotal = Money::of((int) round($quantity * $unitCost->amount));
                $row = new PurchaseLine([
                    'purchase_id' => $purchase->id,
                    'ingredient_id' => $ingredient->id,
                    'quantity_ordered' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'sort_order' => $i,
                ]);
                $row->branch_id = $purchase->branch_id;
                $row->save();
                $total = $total->add($lineTotal);
            }
            $purchase->total = $total;
            $purchase->save();
        });

        return $purchase->unsetRelation('lines');
    }

    public function markOrdered(Purchase $purchase, ?User $by = null): Purchase
    {
        $this->assertOpen($purchase);
        $purchase->forceFill(['status' => PurchaseStatus::Ordered, 'ordered_at' => now()])->save();

        return $purchase;
    }

    /**
     * Goods received: $received maps line id → quantity actually delivered (missing lines = as ordered).
     *
     * @param  array<string, float|string|null>  $received
     */
    public function receive(Purchase $purchase, array $received = [], ?User $by = null, ?string $reference = null, ?string $notes = null): Purchase
    {
        $this->assertOpen($purchase);

        return DB::transaction(function () use ($purchase, $received, $by, $reference, $notes) {
            $total = Money::zero();
            foreach ($purchase->lines()->with('ingredient')->get() as $line) {
                $qty = array_key_exists($line->id, $received) && $received[$line->id] !== null && $received[$line->id] !== ''
                    ? (float) $received[$line->id]
                    : (float) $line->quantity_ordered;
                if ($qty < 0) {
                    throw new InventoryException(__('inventory::inventory.errors.quantity_required'));
                }
                $lineTotal = Money::of((int) round($qty * $line->unit_cost->amount));
                $line->forceFill(['quantity_received' => $qty, 'line_total' => $lineTotal])->save();
                $total = $total->add($lineTotal);

                if ($qty > 0) {
                    $this->stock->move($line->ingredient, StockMovementType::Purchase, $qty, $by, __('inventory::inventory.reasons.purchase', ['number' => $purchase->displayNumber()]), $purchase, $line->unit_cost);
                }
            }

            $purchase->forceFill([
                'status' => PurchaseStatus::Received,
                'received_at' => now(),
                'received_by_id' => $by?->id,
                'reference' => filled($reference) ? trim($reference) : $purchase->reference,
                'notes' => filled($notes) ? trim($notes) : $purchase->notes,
                'total' => $total,
            ])->save();

            PurchaseReceived::dispatch($purchase, $by?->id);

            return $purchase->unsetRelation('lines');
        });
    }

    public function cancel(Purchase $purchase, ?User $by = null): Purchase
    {
        $this->assertOpen($purchase);
        $purchase->forceFill(['status' => PurchaseStatus::Cancelled])->save();

        return $purchase;
    }

    private function assertOpen(Purchase $purchase): void
    {
        if (! $purchase->isOpen()) {
            throw new InventoryException(__('inventory::inventory.errors.purchase_closed', ['status' => $purchase->status->label()]));
        }
    }
}
