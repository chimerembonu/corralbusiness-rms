<?php

namespace App\Modules\Payments\Gateways\Data;

use App\Modules\Payments\Enums\MomoProvider;
use App\Support\Money\Money;

/** Everything a provider needs to take one payment. Amounts are pesewas; never contains card details. */
final class ChargeRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly Money $amount,
        public readonly string $description,
        public readonly ?MomoProvider $network = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $returnUrl = null,
        public readonly string $currency = 'GHS',
    ) {}
}
