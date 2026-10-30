<?php

namespace App\Support\Tax;

/** What a tax component is calculated on. */
enum TaxBase: string
{
    /** The net (pre-tax) amount. Ghana from 1 Jan 2026: VAT, NHIL and GETFund all use this. */
    case Net = 'net';

    /** The net amount PLUS all levies (the old Ghana "cascading" method before 2026). */
    case NetPlusLevies = 'net_plus_levies';

    public function label(): string
    {
        return match ($this) {
            self::Net => __('rms.tax.base.net'),
            self::NetPlusLevies => __('rms.tax.base.net_plus_levies'),
        };
    }
}
