<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Printing\Documents\PrintDocument;

/** The kitchen ticket as printed at a station. Big and simple. */
final class TicketDocument
{
    public static function fromTicket(Ticket $ticket): PrintDocument
    {
        $ticket->loadMissing('items');
        $doc = PrintDocument::make(__('printing::printing.kitchen_title', ['number' => $ticket->displayNumber()]));

        $where = $ticket->table_name ? __('pos::pos.order.table').' '.$ticket->table_name : OrderType::from($ticket->order_type)->label();
        $doc->center()->bold()->big()->text('#'.$ticket->displayNumber().'  '.$where)->normal()
            ->center()->text($ticket->sent_at->format('H:i').($ticket->server_name ? '  '.$ticket->server_name : ''))->normal()
            ->rule('=');

        foreach ($ticket->items as $item) {
            $doc->bold()->tall()->text($item->quantity.' x '.$item->name)->normal();
            foreach ($item->modifiers ?? [] as $modifier) {
                $doc->text('   + '.$modifier);
            }
            if ($item->notes) {
                $doc->bold()->text('   ** '.$item->notes.' **')->normal();
            }
        }

        if ($ticket->notes) {
            $doc->rule()->bold()->text($ticket->notes)->normal();
        }

        return $doc->feed(2)->cut();
    }
}
