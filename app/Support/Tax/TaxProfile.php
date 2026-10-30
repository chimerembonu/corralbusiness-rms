<?php

namespace App\Support\Tax;

/**
 * The set of tax components that apply to a branch, plus whether menu prices
 * already include tax (inclusive) or tax is added on the bill (exclusive).
 */
final class TaxProfile
{
    /** @var array<int, TaxComponentData> */
    public readonly array $components;

    /** @param  array<int, TaxComponentData>  $components */
    public function __construct(array $components, public readonly bool $pricesIncludeTax = false)
    {
        usort($components, fn (TaxComponentData $a, TaxComponentData $b) => [$a->isLevy() ? 0 : 1, $a->sortOrder] <=> [$b->isLevy() ? 0 : 1, $b->sortOrder]);
        $this->components = array_values($components);
    }

    public static function none(bool $pricesIncludeTax = false): self
    {
        return new self([], $pricesIncludeTax);
    }

    /** @return array<int, TaxComponentData> */
    public function levies(): array
    {
        return array_values(array_filter($this->components, fn (TaxComponentData $c) => $c->isLevy()));
    }

    /** @return array<int, TaxComponentData> */
    public function vats(): array
    {
        return array_values(array_filter($this->components, fn (TaxComponentData $c) => ! $c->isLevy()));
    }

    /** @param  array<int, string>  $exceptCodes */
    public function without(array $exceptCodes): self
    {
        return new self(
            array_filter($this->components, fn (TaxComponentData $c) => ! in_array($c->code, $exceptCodes, true)),
            $this->pricesIncludeTax,
        );
    }

    /**
     * Total tax as a fraction of the net amount, e.g. 0.20 for Ghana 2026.
     * Used to back out the net from a tax-inclusive price.
     */
    public function effectiveRate(): float
    {
        $levyRate = 0.0;
        foreach ($this->levies() as $levy) {
            $levyRate += $levy->rateBasisPoints / 10_000;
        }

        $vatRate = 0.0;
        foreach ($this->vats() as $vat) {
            $rate = $vat->rateBasisPoints / 10_000;
            $vatRate += $vat->base === TaxBase::NetPlusLevies ? $rate * (1 + $levyRate) : $rate;
        }

        return $levyRate + $vatRate;
    }

    public function isEmpty(): bool
    {
        return $this->components === [];
    }
}
