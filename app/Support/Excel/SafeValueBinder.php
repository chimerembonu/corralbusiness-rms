<?php

namespace App\Support\Excel;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Every exported cell goes through here (config/excel.php). Text that a spreadsheet would run as a
 * formula — starting with = + - @ or a tab / carriage return — is stored as plain text with a leading
 * apostrophe, so a staff member or menu item named "=HYPERLINK(...)" can never do anything on the
 * owner's laptop (security review L1). Real numbers (including negatives) stay numbers.
 */
class SafeValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && self::looksLikeFormula($value)) {
            $cell->setValueExplicit("'".$value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public static function looksLikeFormula(string $value): bool
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value);
    }
}
