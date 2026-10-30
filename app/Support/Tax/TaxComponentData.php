<?php

namespace App\Support\Tax;

/** One configurable tax line, e.g. VAT 15 % or NHIL 2.5 %. Rate is in basis points (1 % = 100). */
final readonly class TaxComponentData
{
    public function __construct(
        public string $code,
        public string $name,
        public int $rateBasisPoints,
        public TaxKind $kind = TaxKind::Vat,
        public TaxBase $base = TaxBase::Net,
        public int $sortOrder = 0,
    ) {}

    /** 2.5 % → "2.5" */
    public function ratePercent(): string
    {
        return rtrim(rtrim(number_format($this->rateBasisPoints / 100, 2, '.', ''), '0'), '.');
    }

    public function isLevy(): bool
    {
        return $this->kind === TaxKind::Levy;
    }
}
