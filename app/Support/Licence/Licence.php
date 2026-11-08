<?php

namespace App\Support\Licence;

use Carbon\CarbonImmutable;

/** A checked licence: which restaurant, which plan, how many branches, valid until the end of `expires`. */
final class Licence
{
    /** @param  array<int, string>  $extras  feature keys sold on top of the plan */
    public function __construct(
        public readonly string $id,
        public readonly string $restaurant,
        public readonly ?string $tenantId,
        public readonly string $plan,
        public readonly int $branches,
        public readonly CarbonImmutable $issued,
        public readonly CarbonImmutable $expires,
        public readonly array $extras,
        public readonly string $code,
    ) {}
}
