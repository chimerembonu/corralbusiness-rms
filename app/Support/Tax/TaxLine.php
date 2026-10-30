<?php

namespace App\Support\Tax;

use App\Support\Money\Money;

/** One line on the receipt tax summary, e.g. "NHIL 2.5%  GH₵ 2.50". */
final readonly class TaxLine
{
    public function __construct(
        public string $code,
        public string $name,
        public int $rateBasisPoints,
        public TaxKind $kind,
        public Money $amount,
    ) {}

    public function withAmount(Money $amount): self
    {
        return new self($this->code, $this->name, $this->rateBasisPoints, $this->kind, $amount);
    }

    public function ratePercent(): string
    {
        return rtrim(rtrim(number_format($this->rateBasisPoints / 100, 2, '.', ''), '0'), '.');
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'rate_basis_points' => $this->rateBasisPoints,
            'kind' => $this->kind->value,
            'amount' => $this->amount->amount,
        ];
    }
}
