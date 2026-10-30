<?php

namespace App\Support\Tax;

use App\Support\Money\Money;

/**
 * The tax engine (CLAUDE.md §3.6). Pure maths, no database.
 *
 *  - Levies (NHIL, GETFund…) are always calculated on the net amount.
 *  - A VAT component is calculated on the net (Ghana 2026) or on net + levies
 *    (the pre-2026 cascading method) depending on its TaxBase.
 *  - Inclusive pricing backs the net out of the gross so that net + tax == gross exactly.
 *  - Every amount is rounded half-up to the pesewa at this line level.
 */
class TaxCalculator
{
    /**
     * @param  Money  $amount  The price as entered: net when exclusive, gross when the profile is inclusive.
     * @param  bool  $taxable  false for a tax-exempt item → no tax at all.
     * @param  array<int, string>  $exemptCodes  Component codes this item is exempt from (e.g. ['VAT']).
     */
    public function calculate(Money $amount, TaxProfile $profile, bool $taxable = true, array $exemptCodes = []): TaxResult
    {
        if (! $taxable) {
            return new TaxResult($amount, [], $amount);
        }

        $profile = $exemptCodes === [] ? $profile : $profile->without($exemptCodes);

        return $profile->pricesIncludeTax
            ? $this->fromGross($amount, $profile)
            : $this->fromNet($amount, $profile);
    }

    /** Tax is added on top of the net amount. */
    public function fromNet(Money $net, TaxProfile $profile): TaxResult
    {
        $lines = $this->linesFor($net, $profile);
        $gross = $net;
        foreach ($lines as $line) {
            $gross = $gross->add($line->amount);
        }

        return new TaxResult($net, $lines, $gross);
    }

    /** The amount already includes tax; work out the net and each component. */
    public function fromGross(Money $gross, TaxProfile $profile): TaxResult
    {
        if ($profile->isEmpty()) {
            return new TaxResult($gross, [], $gross);
        }

        $net = Money::of(Money::roundHalfUp($gross->amount / (1 + $profile->effectiveRate())), $gross->currency);
        $lines = $this->linesFor($net, $profile);

        // Rounding can leave the parts a pesewa short or over; absorb it in the net so the
        // tax lines always equal rate × net and net + tax == gross.
        $tax = Money::zero($gross->currency);
        foreach ($lines as $line) {
            $tax = $tax->add($line->amount);
        }
        $net = $gross->subtract($tax);

        return new TaxResult($net, $lines, $gross);
    }

    /** @return array<int, TaxLine> */
    private function linesFor(Money $net, TaxProfile $profile): array
    {
        $lines = [];
        $leviesTotal = Money::zero($net->currency);

        foreach ($profile->levies() as $levy) {
            $amount = $net->basisPoints($levy->rateBasisPoints);
            $leviesTotal = $leviesTotal->add($amount);
            $lines[] = new TaxLine($levy->code, $levy->name, $levy->rateBasisPoints, $levy->kind, $amount);
        }

        foreach ($profile->vats() as $vat) {
            $base = $vat->base === TaxBase::NetPlusLevies ? $net->add($leviesTotal) : $net;
            $lines[] = new TaxLine($vat->code, $vat->name, $vat->rateBasisPoints, $vat->kind, $base->basisPoints($vat->rateBasisPoints));
        }

        return $lines;
    }
}
