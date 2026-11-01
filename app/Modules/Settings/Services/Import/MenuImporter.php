<?php

namespace App\Modules\Settings\Services\Import;

use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menu rows: one per item, or several rows for one item (same category + item name) when it has sizes.
 * Items are matched by code when given, else by name within the category; sizes by name.
 */
class MenuImporter
{
    public function preview(string $path): ImportResult
    {
        return $this->run($path, true);
    }

    public function import(string $path): ImportResult
    {
        return $this->run($path, false);
    }

    private function run(string $path, bool $dryRun): ImportResult
    {
        $result = new ImportResult($dryRun);
        $rows = SpreadsheetReader::read($path);

        // Group rows per item, remembering the first spreadsheet row number for messages.
        $groups = $rows->map(fn (array $row, int $i) => $row + ['_row' => $i + 2])
            ->groupBy(fn (array $row) => mb_strtolower(trim((string) ($row['category'] ?? ''))).'|'.mb_strtolower(trim((string) ($row['item'] ?? ''))));

        $work = function () use ($groups, $result, $dryRun) {
            $sort = ['category' => Category::query()->max('sort_order') ?? 0];
            foreach ($groups as $rowsOfItem) {
                $this->importItem($rowsOfItem, $result, $dryRun, $sort);
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

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function importItem(Collection $rows, ImportResult $result, bool $dryRun, array &$sort): void
    {
        $first = $rows->first();
        $rowNo = (int) $first['_row'];
        $categoryName = trim((string) ($first['category'] ?? ''));
        $name = trim((string) ($first['item'] ?? ''));
        $code = trim((string) ($first['code'] ?? '')) ?: null;

        if ($name === '' || $categoryName === '') {
            $result->add($rowNo, 'error', $name ?: '?', __('settings::settings.import.errors.menu_required'));

            return;
        }

        $category = Category::query()->whereRaw('lower(name) = ?', [mb_strtolower($categoryName)])->first()
            ?? Category::create(['name' => $categoryName, 'sort_order' => ++$sort['category']]);

        $sizes = $rows->filter(fn ($r) => trim((string) ($r['size'] ?? '')) !== '')->values();
        $price = SpreadsheetReader::pesewas($first['price'] ?? null)
            ?? ($sizes->isNotEmpty() ? SpreadsheetReader::pesewas($sizes->first()['size_price'] ?? null) : null);
        if ($price === null) {
            $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.price_required'));

            return;
        }

        $item = ($code ? Item::query()->where('code', $code)->first() : null)
            ?? Item::query()->where('category_id', $category->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        $isNew = $item === null;
        $item ??= new Item;

        if ($code && Item::query()->where('code', $code)->whereKeyNot($item->getKey())->exists()) {
            $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.code_taken', ['code' => $code]));

            return;
        }

        $item->fill([
            'category_id' => $category->id,
            'name' => $name,
            'code' => $code,
            'price' => $price,
            'description' => trim((string) ($first['description'] ?? '')) ?: $item->description,
            'taxable' => SpreadsheetReader::bool($first['taxable'] ?? null, true),
            'tags' => isset($first['tags']) && $first['tags'] !== '' ? SpreadsheetReader::list($first['tags']) : $item->tags,
            'track_stock' => SpreadsheetReader::bool($first['count_stock'] ?? null, (bool) $item->track_stock),
            'is_active' => true,
        ]);
        if ($isNew && ($qty = SpreadsheetReader::number($first['stock_qty'] ?? null)) !== null) {
            $item->stock_qty = $qty;
        }
        $item->save();

        $sizeNames = [];
        foreach ($sizes as $i => $row) {
            $sizeName = trim((string) $row['size']);
            $sizePrice = SpreadsheetReader::pesewas($row['size_price'] ?? null) ?? $price;
            $sizeNames[] = mb_strtolower($sizeName);
            $variant = $item->variants()->whereRaw('lower(name) = ?', [mb_strtolower($sizeName)])->first() ?? new ItemVariant(['item_id' => $item->id]);
            $variant->fill(['name' => $sizeName, 'price' => $sizePrice, 'is_default' => $i === 0, 'sort_order' => $i, 'is_active' => true]);
            $variant->item_id = $item->id;
            $variant->save();
        }

        $label = $name.($sizes->isNotEmpty() ? ' ('.$sizes->count().' '.__('settings::settings.import.sizes').')' : '');
        $result->add($rowNo, $isNew ? 'new' : 'update', $label, $category->name.' · '.money($price));
    }
}
