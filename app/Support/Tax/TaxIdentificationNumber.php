<?php

namespace App\Support\Tax;

/**
 * A buyer's Ghana TIN, printed on the receipt so a business customer can claim input VAT
 * (VAT Act 2025; compliance assessment). Two forms are accepted:
 *  - the classic GRA TIN: one letter + 10 digits, e.g. C0012345678 / P0012345678
 *  - the Ghana Card PIN, which is now the TIN for individuals: GHA-123456789-0
 */
final class TaxIdentificationNumber
{
    /** Tidy what was typed (case, spaces, dashes). Returns null when empty, false when it is not a valid TIN. */
    public static function normalise(?string $value): string|null|false
    {
        $value = strtoupper(preg_replace('/\s+/', '', (string) $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[A-Z]\d{10}$/', $value)) {
            return $value;
        }
        if (preg_match('/^GHA-?(\d{9})-?(\d)$/', $value, $m)) {
            return "GHA-{$m[1]}-{$m[2]}";
        }

        return false;
    }
}
