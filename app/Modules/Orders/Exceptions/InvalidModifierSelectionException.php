<?php

namespace App\Modules\Orders\Exceptions;

use RuntimeException;

class InvalidModifierSelectionException extends RuntimeException
{
    /** @param  array<int, string>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
