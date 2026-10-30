<?php

use App\Support\Money\Money;

if (! function_exists('money')) {
    /**
     * The single display helper for money (CLAUDE.md §3.5).
     *
     *   money(1250)              → "GH₵ 12.50"
     *   money(Money::of(1250))   → "GH₵ 12.50"
     *   money(1250, false)       → "12.50"
     */
    function money(Money|int|null $amount, bool $withSymbol = true): string
    {
        if ($amount === null) {
            $amount = Money::zero();
        }

        if (is_int($amount)) {
            $amount = Money::of($amount);
        }

        return $amount->format($withSymbol);
    }
}
