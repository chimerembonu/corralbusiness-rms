<?php

namespace App\Modules\Payments\Exceptions;

/** The business day was closed (Z report printed); nothing more may be sold or paid on it. */
class DayClosedException extends CashException
{
    public function __construct(public readonly string $businessDate)
    {
        parent::__construct(__('payments::payments.day.errors.closed', ['date' => $businessDate]));
    }
}
