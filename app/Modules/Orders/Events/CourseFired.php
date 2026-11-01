<?php

namespace App\Modules\Orders\Events;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Support\Collection;

/** "Fire course 2": the kitchen may now cook these lines. */
class CourseFired extends OrderEvent
{
    /** @param  Collection<int, OrderItem>  $items */
    public function __construct(Order $order, public readonly int $course, public readonly Collection $items, ?string $byUserId = null)
    {
        parent::__construct($order, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'orders.course_fired';
    }

    protected function extraPayload(): array
    {
        return ['course' => $this->course, 'item_ids' => $this->items->pluck('id')->all()];
    }
}
