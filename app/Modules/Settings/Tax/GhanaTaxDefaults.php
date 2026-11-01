<?php

namespace App\Modules\Settings\Tax;

use App\Support\Tax\TaxBase;
use App\Support\Tax\TaxKind;

/**
 * DEFAULT GHANA TAX RATES – confirmed by the owner on 2 November 2026 (VAT 15 %, NHIL 2.5 %, GETFund 2.5 %).
 *
 * Source: Ghana Revenue Authority, "VAT" page (gra.gov.gh/domestic-tax/tax-types/vat),
 * checked on 30 October 2026. Under the Value Added Tax Act, 2025 (Act 1151), in force
 * from 1 January 2026:
 *   - VAT                       15 %   on the net price
 *   - NHIL (National Health Insurance Levy)   2.5 % on the net price
 *   - GETFund Levy              2.5 %  on the net price
 *   - COVID-19 Health Recovery Levy (1 %)  ABOLISHED (repealed from 1 Jan 2026)
 * All three are charged on the SAME base (no more cascading), so GHS 1,000 net → GHS 1,200.
 * Before 2026 VAT was charged on net + levies (GHS 1,000 → GHS 1,219); that older method is
 * still supported by setting a VAT component's base to "net_plus_levies".
 *
 * The owner can change every rate in Settings → Taxes; nothing here is hard-wired.
 */
final class GhanaTaxDefaults
{
    public const CONFIRMATION_NOTE = 'Ghana rates: VAT 15 %, NHIL 2.5 %, GETFund 2.5 % on the net price (confirmed 2 Nov 2026). Change them here if the law changes.';

    /** @return array<int, array<string, mixed>> */
    public static function components(): array
    {
        return [
            [
                'code' => 'NHIL',
                'name' => 'NHIL',
                'rate_basis_points' => 250,
                'kind' => TaxKind::Levy,
                'base' => TaxBase::Net,
                'sort_order' => 1,
            ],
            [
                'code' => 'GETFUND',
                'name' => 'GETFund Levy',
                'rate_basis_points' => 250,
                'kind' => TaxKind::Levy,
                'base' => TaxBase::Net,
                'sort_order' => 2,
            ],
            [
                'code' => 'VAT',
                'name' => 'VAT',
                'rate_basis_points' => 1500,
                'kind' => TaxKind::Vat,
                'base' => TaxBase::Net,
                'sort_order' => 3,
            ],
        ];
    }

    /** Menu prices are entered WITHOUT tax by default (tax is added on the bill). Owner can switch. */
    public const PRICES_INCLUDE_TAX = false;
}
