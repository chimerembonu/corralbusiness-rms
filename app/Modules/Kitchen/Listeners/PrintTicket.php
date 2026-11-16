<?php

namespace App\Modules\Kitchen\Listeners;

use App\Modules\Kitchen\Events\TicketChanged;
use App\Modules\Kitchen\Services\TicketDocument;
use App\Modules\Printing\Enums\PrintJobType;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\PrintService;

/** Prints a new ticket (or a fire / do-not-cook slip) on every active printer flagged "prints kitchen tickets" at the branch. */
class PrintTicket
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(TicketChanged $event): void
    {
        if (! in_array($event->change, ['created', 'fired', 'cancelled'], true)) {
            return;
        }

        $document = match ($event->change) {
            'fired' => TicketDocument::fireSlip($event->ticket),
            'cancelled' => TicketDocument::cancelSlip($event->ticket),
            default => TicketDocument::fromTicket($event->ticket),
        };

        $printers = Printer::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('branch_id', $event->ticket->branch_id)->where('is_active', true)->where('prints_kitchen_tickets', true)->get()
            ->filter(fn (Printer $p) => empty($p->station_ids) || in_array($event->ticket->station_id, $p->station_ids, true));

        foreach ($printers as $printer) {
            $this->printing->enqueue($printer, $document, PrintJobType::KitchenTicket, $event->ticket);
        }
    }
}
