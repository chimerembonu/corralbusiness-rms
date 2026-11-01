<?php

namespace App\Modules\Staff\Events;

class ClockedOut extends TimeEntryEvent
{
    public function outboxEventType(): string
    {
        return 'staff.clocked_out';
    }
}
