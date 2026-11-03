<?php

namespace App\Support\Money;

use App\Support\Errors\UserFacingException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money value (CLAUDE.md §3.5).
 *
 * Amounts are integers in the smallest unit (pesewas for GHS). Never floats.
 * Multiplying by a rate rounds HALF-UP to the nearest pesewa.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }
    }

    // ---- Constructors ------------------------------------------------------

    /** From an integer number of pesewas. */
    public static function of(int $amount, ?string $currency = null): self
    {
        return new self($amount, strtoupper($currency ?? self::defaultCurrency()));
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    /**
     * From what a person typed in a money box: "10", "12.50", "GH₵ 10", "GHS10", "₵1,200.00".
     * The currency sign and spaces are ignored; anything that is still not a number is refused with a
     * message staff can read (a domain error, so screens show it as it is).
     */
    public static function fromTyped(?string $typed, ?string $currency = null): self
    {
        $clean = trim(str_ireplace(['GH₵', 'GH¢', 'GHS', 'GHC', '₵', '¢', 'cedis', 'cedi'], '', (string) $typed));
        if ($clean === '') {
            return self::zero($currency);
        }
        if (! preg_match('/^\d[\d,]*(\.\d+)?$/', str_replace(' ', '', $clean))) {
            throw new UserFacingException(__('errors.action.amount'));
        }

        return self::fromDecimal($clean, $currency);
    }

    /** From a decimal string or number of cedis, e.g. "12.50" or 12.5 → 1250 pesewas. */
    public static function fromDecimal(string|int|float $decimal, ?string $currency = null): self
    {
        $string = is_string($decimal) ? trim($decimal) : number_format((float) $decimal, 6, '.', '');
        $string = str_replace([',', ' '], '', $string);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $string, $m)) {
            throw new InvalidArgumentException("Cannot parse money amount [{$decimal}].");
        }

        $minorUnits = self::minorUnits();
        $fraction = str_pad(substr($m[3] ?? '', 0, $minorUnits + 1), $minorUnits + 1, '0');
        $rounded = intdiv((int) $fraction + 5, 10); // half-up on the extra digit
        $amount = ((int) $m[2]) * (10 ** $minorUnits) + $rounded;

        return self::of($m[1] === '-' ? -$amount : $amount, $currency);
    }

    // ---- Arithmetic --------------------------------------------------------

    public function add(Money ...$others): self
    {
        $total = $this->amount;
        foreach ($others as $other) {
            $this->assertSameCurrency($other);
            $total += $other->amount;
        }

        return self::of($total, $this->currency);
    }

    public function subtract(Money ...$others): self
    {
        $total = $this->amount;
        foreach ($others as $other) {
            $this->assertSameCurrency($other);
            $total -= $other->amount;
        }

        return self::of($total, $this->currency);
    }

    /** Multiply by a plain number (e.g. quantity 3, or ratio 0.5) and round half-up. */
    public function multiply(int|float|string $factor): self
    {
        if (is_int($factor)) {
            return self::of($this->amount * $factor, $this->currency);
        }

        return self::of(self::roundHalfUp((float) $factor * $this->amount), $this->currency);
    }

    /** Apply a percentage, e.g. percentage(15) → 15 % of this amount, rounded half-up. */
    public function percentage(int|float|string $percent): self
    {
        return self::of(self::roundHalfUp($this->amount * (float) $percent / 100), $this->currency);
    }

    /** Apply a rate expressed in basis points (1 % = 100 bp) using integer maths only. */
    public function basisPoints(int $basisPoints): self
    {
        $product = $this->amount * $basisPoints;
        $sign = $product < 0 ? -1 : 1;
        $rounded = intdiv(abs($product) + 5_000, 10_000);

        return self::of($sign * $rounded, $this->currency);
    }

    /**
     * Split into N parts without losing a pesewa (e.g. split bill by seats).
     * The remainder goes to the first parts: 1000 / 3 → [334, 333, 333].
     *
     * @return array<int, Money>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot split money into fewer than 1 part.');
        }

        return $this->allocate(array_fill(0, $parts, 1));
    }

    /**
     * Allocate by ratios without losing a pesewa (e.g. discount spread across items).
     *
     * @param  array<int|string, int|float>  $ratios
     * @return array<int|string, Money>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);
        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation ratios must add up to more than zero.');
        }

        $remainder = $this->amount;
        $results = [];
        foreach ($ratios as $key => $ratio) {
            $share = (int) floor($this->amount * $ratio / $total);
            $results[$key] = $share;
            $remainder -= $share;
        }

        // Hand out the leftover pesewas one by one from the first ratio onwards.
        foreach (array_keys($results) as $key) {
            if ($remainder === 0) {
                break;
            }
            $results[$key] += $remainder > 0 ? 1 : -1;
            $remainder += $remainder > 0 ? -1 : 1;
        }

        return array_map(fn (int $amount) => self::of($amount, $this->currency), $results);
    }

    public function negate(): self
    {
        return self::of(-$this->amount, $this->currency);
    }

    public function abs(): self
    {
        return self::of(abs($this->amount), $this->currency);
    }

    // ---- Comparison --------------------------------------------------------

    public function equals(Money $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(Money $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    // ---- Output ------------------------------------------------------------

    /** "12.50" – decimal string without a symbol (for exports and APIs). */
    public function toDecimal(): string
    {
        $minor = self::minorUnits();
        $abs = abs($this->amount);
        $whole = intdiv($abs, 10 ** $minor);
        $fraction = str_pad((string) ($abs % (10 ** $minor)), $minor, '0', STR_PAD_LEFT);

        return ($this->amount < 0 ? '-' : '').$whole.'.'.$fraction;
    }

    /** "GH₵ 1,250.00" – for screens and receipts. */
    public function format(bool $withSymbol = true): string
    {
        $minor = self::minorUnits();
        $abs = abs($this->amount);
        $formatted = number_format($abs / (10 ** $minor), $minor, '.', ',');
        $sign = $this->amount < 0 ? '-' : '';

        if (! $withSymbol) {
            return $sign.$formatted;
        }

        return $sign.config('rms.currency_symbol', $this->currency).' '.$formatted;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    // ---- Internals ---------------------------------------------------------

    private static function defaultCurrency(): string
    {
        return (string) config('rms.currency', 'GHS');
    }

    private static function minorUnits(): int
    {
        return (int) config('rms.currency_minor_units', 2);
    }

    /** Round half away from zero (0.5 → 1, -0.5 → -1) which is "half-up" for receipts. */
    public static function roundHalfUp(float $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
