<?php

namespace App\Support\Security;

/**
 * Keeps full card numbers (PANs) out of the system, so the till, its backups and the cloud sync stay
 * outside PCI DSS scope (compliance assessment, 4 Nov 2026). Cards are paid on the bank's own terminal;
 * the till only ever needs the slip number or the last 4 digits.
 */
final class CardNumberGuard
{
    /** A card payment's reference: slip number or last 4 digits, so never more than 12 digits in total. */
    public static function isSafeCardReference(?string $reference): bool
    {
        return strlen(preg_replace('/\D/', '', (string) $reference)) <= 12;
    }

    /** Free text (notes): refuse anything that looks like a real card number (13–19 digits passing the Luhn check). */
    public static function containsCardNumber(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }
        // Runs of digits, allowing the spaces or dashes people type between groups.
        preg_match_all('/\d(?:[ \-]?\d){12,18}/', $text, $matches);
        foreach ($matches[0] as $run) {
            $digits = preg_replace('/\D/', '', $run);
            // Any 13–19 digit window inside a longer run could be the number.
            for ($len = 13; $len <= min(19, strlen($digits)); $len++) {
                for ($i = 0; $i + $len <= strlen($digits); $i++) {
                    if (self::luhn(substr($digits, $i, $len))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
