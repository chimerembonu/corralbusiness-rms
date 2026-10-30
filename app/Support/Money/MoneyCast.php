<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast: integer pesewas column  ⇄  Money object.
 *
 *   protected function casts(): array { return ['price' => MoneyCast::class]; }
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::of((int) $value, $attributes['currency'] ?? null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->amount;
        }

        if (is_int($value)) {
            return $value;
        }

        return Money::fromDecimal($value)->amount;
    }
}
