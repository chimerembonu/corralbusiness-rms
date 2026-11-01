<?php

namespace App\Modules\Pos\Services;

use Illuminate\Support\Facades\Cookie;

/**
 * Training mode is switched on per device (cookie): new staff practise on one till while the
 * others trade. Orders taken while it is on are marked and never count in reports or cash-ups.
 */
class TrainingMode
{
    public const COOKIE = 'rms_training';

    private ?bool $on = null;

    public function isOn(): bool
    {
        return $this->on ?? ($this->on = request()?->cookie(self::COOKIE) === '1');
    }

    public function set(bool $on): void
    {
        Cookie::queue($on ? Cookie::make(self::COOKIE, '1', 60 * 24 * 7, httpOnly: true) : Cookie::forget(self::COOKIE));
        $this->on = $on;
    }
}
