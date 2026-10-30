<?php

namespace App\Support\Tax;

enum TaxKind: string
{
    case Vat = 'vat';
    case Levy = 'levy';

    public function label(): string
    {
        return match ($this) {
            self::Vat => __('rms.tax.kind.vat'),
            self::Levy => __('rms.tax.kind.levy'),
        };
    }
}
