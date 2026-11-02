<?php

namespace App\Modules\Staff\Exceptions;

use RuntimeException;

/** Thrown when a PIN was correct but that person is not allowed to approve the action. */
class ManagerPinRequiredException extends RuntimeException
{
    public function __construct(public readonly string $permission, ?string $message = null)
    {
        parent::__construct($message ?? __('rms.pin.manager_required'));
    }
}
