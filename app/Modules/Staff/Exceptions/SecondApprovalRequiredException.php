<?php

namespace App\Modules\Staff\Exceptions;

use RuntimeException;

/** Till settings ask for a second manager to confirm this (a big refund, a day close), and none did (E9.4). */
class SecondApprovalRequiredException extends RuntimeException
{
    public function __construct(public readonly string $permission, ?string $message = null)
    {
        parent::__construct($message ?? __('rms.pin.second_approval_required'));
    }
}
