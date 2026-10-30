<?php

namespace App\Modules\Staff\Exceptions;

use RuntimeException;

class PinLockedException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(__('rms.pin.locked', ['seconds' => $retryAfterSeconds]));
    }
}
