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
            if ($item->is_held) {
                $doc->bold()->text('>> '.__('kitchen::kitchen.hold_course', ['number' => $item->course]).' <<')->normal();
            }
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

    /** Short slip printed when a held course is fired: only the lines that were just released. */
    public static function fireSlip(Ticket $ticket): PrintDocument
    {
        $ticket->load('items');
        $items = $ticket->items->where('is_held', false)->where('is_voided', false)->filter(fn ($i) => ($i->course ?? 1) > 1);
        $course = $items->max('course') ?? 2;
        $items = $items->where('course', $course);

        $where = $ticket->table_name ? __('pos::pos.order.table').' '.$ticket->table_name : OrderType::from($ticket->order_type)->label();
        $doc = PrintDocument::make(__('kitchen::kitchen.fire_title', ['number' => $ticket->displayNumber()]));
        $doc->center()->bold()->big()->text(__('kitchen::kitchen.fire_course', ['number' => $course]))->normal()
            ->center()->bold()->text('#'.$ticket->displayNumber().'  '.$where)->normal()
            ->center()->text(now()->format('H:i'))->rule('=');

        foreach ($items as $item) {
            $doc->bold()->tall()->text($item->quantity.' x '.$item->name)->normal();
            foreach ($item->modifiers ?? [] as $modifier) {
                $doc->text('   + '.$modifier);
            }
            if ($item->notes) {
                $doc->bold()->text('   ** '.$item->notes.' **')->normal();
            }
        }

        return $doc->feed(2)->cut();
    }
}
