<?php

namespace App\Modules\Payments\Enums;

/** Ghana's mobile money networks (manual recording at launch, aggregator in Phase 7). */
enum MomoProvider: string
{
    case Mtn = 'mtn';
    case Telecel = 'telecel';
    case At = 'at';

    public function label(): string
    {
        return __('payments::payments.provider.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(array_map(fn (self $p) => $p->value, self::cases()), array_map(fn (self $p) => $p->label(), self::cases()));
    }
}
