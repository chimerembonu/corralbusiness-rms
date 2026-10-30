<?php

namespace App\Modules\Staff\Support;

use InvalidArgumentException;

/**
 * PINs are stored twice: a bcrypt hash (verified on login) and a keyed HMAC "lookup"
 * so the matching user can be found in one indexed query instead of checking every
 * staff member's hash. The HMAC uses APP_KEY, so a stolen database alone cannot
 * recover PINs by brute force.
 */
final class PinHasher
{
    public static function lookup(?string $tenantId, string $pin): string
    {
        return hash_hmac('sha256', ($tenantId ?? 'global').'|'.$pin, (string) config('app.key'));
    }

    public static function validate(string $pin): string
    {
        $min = (int) config('rms.pin.min_length', 4);
        $max = (int) config('rms.pin.max_length', 6);

        if (! preg_match('/^\d{'.$min.','.$max.'}$/', $pin)) {
            throw new InvalidArgumentException(__('rms.pin.invalid_format', ['min' => $min, 'max' => $max]));
        }

        return $pin;
    }
}
