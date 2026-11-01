<?php

namespace App\Modules\Kitchen\Listeners;

use App\Modules\Kitchen\Events\TicketChanged;
use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Orders\Events\OrderTransferred;
use App\Modules\Orders\Events\TablesMerged;

/** Keeps kitchen tickets pointing at the right table/order after a move or a merge. */
class FollowOrderToNewTable
{
    public function handle(OrderTransferred|TablesMerged $event): void
    {
        $order = $event->order;

        if ($event instanceof TablesMerged && $event->sourceOrderId) {
            Ticket::query()->where('order_id', $event->sourceOrderId)->update(['order_id' => $order->id]);
        }

        $tickets = Ticket::query()->active()->where('order_id', $order->id)->get();

        foreach ($tickets as $ticket) {
            $ticket->forceFill(['table_name' => $order->table?->name, 'order_number' => $order->number])->save();
            TicketChanged::dispatch($ticket, 'moved');
        }
    }
}
