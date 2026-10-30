<?php

namespace App\Modules\Kitchen\Listeners;

use App\Modules\Kitchen\Events\TicketChanged;
use App\Modules\Kitchen\Services\TicketDocument;
use App\Modules\Printing\Enums\PrintJobType;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Services\PrintService;

/** Prints a new ticket on every active printer flagged "prints kitchen tickets" at the branch. */
class PrintTicket
{
    public function __construct(private readonly PrintService $printing) {}

    public function handle(TicketChanged $event): void
    {
        if ($event->change !== 'created') {
            return;
        }

        $printers = Printer::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('branch_id', $event->ticket->branch_id)->where('is_active', true)->where('prints_kitchen_tickets', true)->get();

        foreach ($printers as $printer) {
            $this->printing->enqueue($printer, TicketDocument::fromTicket($event->ticket), PrintJobType::KitchenTicket, $event->ticket);
        }
    }
}
