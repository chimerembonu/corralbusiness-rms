<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\Till;
use Illuminate\Support\Facades\Cookie;

/** Which till this device is (chosen once at sign-in, remembered in a cookie for a year). */
class CurrentTill
{
    public const COOKIE = 'rms_till';

    private ?Till $till = null;

    private bool $resolved = false;

    public function id(): ?string
    {
        $id = request()?->cookie(self::COOKIE);

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function get(): ?Till
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $this->till = $this->id() ? Till::query()->active()->find($this->id()) : null;
        }

        return $this->till;
    }

    /** Remember the till on this device. */
    public function remember(Till $till): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $till->id, 60 * 24 * 365, httpOnly: true));
        $this->till = $till;
        $this->resolved = true;
    }

    /**
     * What a PIN lockout is counted against: this till when the device has chosen one, else the
     * network address. Tills behind one router share an address, so one bad actor must not lock them all
     * (security review L4).
     */
    public function throttleKey(): string
    {
        return 'till:'.($this->get()?->id ?? $this->id() ?? request()?->ip() ?? 'unknown');
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
        $this->till = null;
        $this->resolved = true;
    }
}
