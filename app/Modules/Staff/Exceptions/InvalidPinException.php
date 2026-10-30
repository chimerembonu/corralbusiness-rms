<?php

namespace App\Modules\Staff\Exceptions;

use RuntimeException;

class InvalidPinException extends RuntimeException
{
    public function __construct(public readonly int $attemptsLeft)
    {
        parent::__construct(__('rms.pin.invalid', ['left' => $attemptsLeft]));
    }
}
