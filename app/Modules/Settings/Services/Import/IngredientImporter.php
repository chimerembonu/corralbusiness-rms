<?php

namespace App\Modules\Settings\Services\Import;

use App\Modules\Inventory\Enums\CostChangeSource;
use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\CostService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/** Ingredient rows matched by code, else by name. "In stock" only sets the opening stock of NEW ingredients. */
class IngredientImporter
{
    public function __construct(private readonly StockService $stock) {}

    public function preview(string $path): ImportResult
    {
        return $this->run($path, true, null);
    }

    public function import(string $path, ?User $by = null): ImportResult
    {
        return $this->run($path, false, $by);
    }

    private function run(string $path, bool $dryRun, ?User $by): ImportResult
    {
        $result = new ImportResult($dryRun);
        $rows = SpreadsheetReader::read($path);

        $work = function () use ($rows, $result, $by) {
            foreach ($rows as $i => $row) {
                $this->importRow($i + 2, $row, $result, $by);
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $work();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($work);
        }

        return $result;
    }

    /** @param  array<string, mixed>  $row */
    private function importRow(int $rowNo, array $row, ImportResult $result, ?User $by): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $code = trim((string) ($row['code'] ?? '')) ?: null;
        if ($name === '') {
            $result->add($rowNo, 'error', '?', __('settings::settings.import.errors.name_required'));

            return;
        }
        $unit = Unit::tryFrom(strtolower(trim((string) ($row['unit'] ?? 'kg')))) ?? Unit::tryFrom(strtolower(trim((string) ($row['unit'] ?? ''))) === 'litre' ? 'l' : '');
        if (! $unit) {
            $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.bad_unit', ['unit' => $row['unit'] ?? '', 'units' => implode(', ', array_map(fn (Unit $u) => $u->value, Unit::cases()))]));

            return;
        }

        $ingredient = ($code ? Ingredient::query()->where('code', $code)->first() : null)
            ?? Ingredient::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        $isNew = $ingredient === null;
        $ingredient ??= new Ingredient;
        $oldCost = $ingredient->cost_per_unit ?? Money::zero();

        $supplierName = trim((string) ($row['supplier'] ?? ''));
        $supplier = $supplierName !== '' ? (Supplier::query()->whereRaw('lower(name) = ?', [mb_strtolower($supplierName)])->first() ?? Supplier::create(['name' => $supplierName])) : null;

        $ingredient->fill([
            'name' => $name,
            'code' => $code ?? $ingredient->code,
            'category' => trim((string) ($row['category'] ?? '')) ?: $ingredient->category,
            'unit' => $unit->value,
            'pack_name' => trim((string) ($row['pack_name'] ?? '')) ?: $ingredient->pack_name,
            'pack_quantity' => SpreadsheetReader::number($row['pack_quantity'] ?? null) ?? $ingredient->pack_quantity,
            'cost_per_unit' => SpreadsheetReader::pesewas($row['cost_per_unit'] ?? null) ?? ($ingredient->cost_per_unit?->amount ?? 0),
            'reorder_level' => SpreadsheetReader::number($row['reorder_level'] ?? null) ?? ($ingredient->reorder_level ?? 0),
            'supplier_id' => $supplier?->id ?? $ingredient->supplier_id,
            'is_active' => true,
        ]);
        if ($isNew) {
            $ingredient->on_hand = SpreadsheetReader::number($row['in_stock'] ?? null) ?? 0;
        }
        $ingredient->save();
        if ($isNew) {
            $this->stock->recordOpening($ingredient, $by);
            app(CostService::class)->recordOpening($ingredient, $by, CostChangeSource::Import);
        } else {
            app(CostService::class)->recordImport($ingredient, $oldCost, $by); // kept in the price history (E5.2)
        }

        $note = $isNew ? money($ingredient->cost_per_unit).' / '.$unit->short() : __('settings::settings.import.stock_unchanged');
        $result->add($rowNo, $isNew ? 'new' : 'update', $name, $note);
    }
}
