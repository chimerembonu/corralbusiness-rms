<?php

namespace App\Modules\Menu\Support;

/** Normalises the availability JSON (empty form → null = always). */
trait HasAvailability
{
    public function setAvailabilityAttribute(mixed $value): void
    {
        $normalised = Availability::fromArray(is_array($value) ? $value : null)->toArray();

        $this->attributes['availability'] = $normalised === null ? null : json_encode($normalised);
    }

    public function availabilityRule(): Availability
    {
        return Availability::fromArray($this->availability);
    }
}
