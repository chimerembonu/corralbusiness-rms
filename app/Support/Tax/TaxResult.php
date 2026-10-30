<?php

namespace App\Support\Tax;

use App\Support\Money\Money;

/** Net + tax lines + gross for one line or one whole order. Always: net + tax == gross. */
final class TaxResult
{
    /** @param  array<int, TaxLine>  $lines */
    public function __construct(
        public readonly Money $net,
        public readonly array $lines,
        public readonly Money $gross,
    ) {}

    public function tax(): Money
    {
        $total = Money::zero($this->net->currency);
        foreach ($this->lines as $line) {
            $total = $total->add($line->amount);
        }

        return $total;
    }

    public function line(string $code): ?TaxLine
    {
        foreach ($this->lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    /** Add up several results (order = sum of its lines), merging tax lines by code. */
    public static function sum(TaxResult ...$results): self
    {
        if ($results === []) {
            return new self(Money::zero(), [], Money::zero());
        }

        $net = Money::zero($results[0]->net->currency);
        $gross = Money::zero($results[0]->net->currency);
        /** @var array<string, TaxLine> $lines */
        $lines = [];

        foreach ($results as $result) {
            $net = $net->add($result->net);
            $gross = $gross->add($result->gross);
            foreach ($result->lines as $line) {
                $lines[$line->code] = isset($lines[$line->code])
                    ? $lines[$line->code]->withAmount($lines[$line->code]->amount->add($line->amount))
                    : $line;
            }
        }

        return new self($net, array_values($lines), $gross);
    }

    public function toArray(): array
    {
        return [
            'net' => $this->net->amount,
            'tax' => $this->tax()->amount,
            'gross' => $this->gross->amount,
            'lines' => array_map(fn (TaxLine $l) => $l->toArray(), $this->lines),
        ];
    }
}
