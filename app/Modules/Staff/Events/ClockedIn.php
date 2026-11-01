<?php

namespace App\Modules\Staff\Events;

class ClockedIn extends TimeEntryEvent
{
    public function outboxEventType(): string
    {
        return 'staff.clocked_in';
    }
}
