<?php

namespace App\Modules\Orders\Events;

class ItemCourseChanged extends OrderItemEvent
{
    public function outboxEventType(): string
    {
        return 'orders.item_course_changed';
    }
}
