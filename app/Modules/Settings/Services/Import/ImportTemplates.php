<?php

namespace App\Modules\Settings\Services\Import;

use App\Modules\Reports\Exports\TableExport;

/** The spreadsheets the owner downloads, fills in and uploads. One example row each. */
final class ImportTemplates
{
    public const TYPES = ['menu', 'ingredients', 'staff'];

    /** @return array<int, string> */
    public static function headings(string $type): array
    {
        return match ($type) {
            'menu' => ['Category', 'Item', 'Code', 'Price', 'Size', 'Size price', 'Description', 'Taxable', 'Tags', 'Count stock', 'Stock qty'],
            'ingredients' => ['Name', 'Code', 'Category', 'Unit', 'Pack name', 'Pack quantity', 'Cost per unit', 'In stock', 'Reorder level', 'Supplier'],
            'staff' => ['Name', 'Phone', 'Roles', 'PIN', 'Job title', 'Email', 'Started on'],
            default => [],
        };
    }

    /** @return array<int, array<int, mixed>> */
    public static function examples(string $type): array
    {
        return match ($type) {
            'menu' => [
                ['Rice', 'Jollof Rice', 'JOL', '45.00', 'Regular', '45.00', 'With chicken or beef', 'yes', 'popular', 'no', ''],
                ['Rice', 'Jollof Rice', 'JOL', '', 'Large', '60.00', '', 'yes', '', 'no', ''],
                ['Drinks', 'Club Beer', 'CLB', '15.00', '', '', '', 'yes', '', 'yes', '48'],
                ['Drinks', 'Bottled Water', 'WAT', '5.00', '', '', '', 'no', '', 'yes', '120'],
            ],
            'ingredients' => [
                ['Long-grain rice', 'ING001', 'Dry goods', 'kg', 'Bag', '25', '22.00', '80', '25', 'Makola Market Traders'],
                ['Eggs', 'ING019', 'Dairy & eggs', 'pcs', 'Crate', '30', '2.50', '180', '60', ''],
            ],
            'staff' => [
                ['Kofi Boateng', '024 100 0001', 'cashier', '1001', 'Senior cashier', '', '2026-01-15'],
                ['Akosua Mensah', '024 100 0002', 'manager, cashier', '2468', 'Restaurant manager', 'akosua@example.com', '2025-06-01'],
            ],
            default => [],
        };
    }

    public static function export(string $type): TableExport
    {
        return new TableExport(__('settings::settings.import.types.'.$type), self::headings($type), self::examples($type));
    }

    public static function fileName(string $type): string
    {
        return "corralbusiness-{$type}-template.xlsx";
    }
}
