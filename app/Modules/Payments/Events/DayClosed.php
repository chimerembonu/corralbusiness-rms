<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\DayClose;

class DayClosed extends CashEvent
{
    public function __construct(public readonly DayClose $dayClose, ?string $byUserId = null)
    {
        parent::__construct($dayClose, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'cash.day_closed';
    }

    protected function extraPayload(): array
    {
        return ['business_date' => $this->dayClose->business_date->toDateString(), 'number' => $this->dayClose->number];
    }
}
