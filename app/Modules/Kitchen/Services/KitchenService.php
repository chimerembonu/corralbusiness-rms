<?php

namespace App\Modules\Kitchen\Services;

use App\Modules\Kitchen\Enums\TicketStatus;
use App\Modules\Kitchen\Events\TicketChanged;
use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Models\TicketItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Staff\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

class KitchenService
{
    public function __construct(private readonly RoutingService $routing) {}

    /**
     * One ticket per station for the lines just sent (lines with no station go to the default
     * station; if there is none, they get a ticket with no station so nothing is lost).
     *
     * @param  BaseCollection<int, OrderItem>  $items  the lines just sent
     * @return BaseCollection<int, Ticket>
     */
    public function createTickets(Order $order, BaseCollection $items): BaseCollection
    {
        $groups = $this->routing->groupByStation(collect($items->all()), $order->branch_id);

        return $groups->map(fn (BaseCollection $lines, string $stationId) => $this->createTicket($order, $lines, $stationId ?: null))->values();
    }

    /** @param  BaseCollection<int, OrderItem>  $items  the lines just sent */
    public function createTicket(Order $order, BaseCollection $items, ?string $stationId = null): Ticket
    {
        $order->loadMissing(['table', 'openedBy']);

        return DB::transaction(function () use ($order, $items, $stationId) {
            $ticket = new Ticket([
                'order_id' => $order->id,
                'station_id' => $stationId,
                'order_number' => $order->number,
                'order_type' => $order->type->value,
                'table_name' => $order->table?->name,
                'server_name' => $order->openedBy?->name,
                'is_training' => (bool) $order->is_training,
                'notes' => $order->isDelivery()
                    ? trim(($order->customer_name ? $order->customer_name.' · ' : '').$order->delivery_address.($order->notes ? ' · '.$order->notes : ''))
                    : $order->notes,
                'sent_at' => now(),
            ]);
            $ticket->branch_id = $order->branch_id;
            $ticket->save();

            foreach ($items->values() as $i => $line) {
                $line->loadMissing('modifiers');
                $item = new TicketItem([
                    'ticket_id' => $ticket->id,
                    'order_item_id' => $line->id,
                    'name' => $line->displayName(),
                    'quantity' => $line->quantity,
                    'modifiers' => $line->modifiers->map->displayName()->all(),
                    'notes' => $line->notes,
                    'seat' => $line->seat,
                    'course' => $line->course,
                    'is_held' => ($line->course ?? 1) > 1 && $line->fired_at === null,
                    'sort_order' => $i,
                ]);
                $item->branch_id = $order->branch_id;
                $item->save();
            }

            TicketChanged::dispatch($ticket, 'created');

            return $ticket;
        });
    }

    public function start(Ticket $ticket, ?User $by = null): Ticket
    {
        if ($ticket->status !== TicketStatus::New) {
            return $ticket;
        }

        $ticket->forceFill(['status' => TicketStatus::InProgress, 'started_at' => now(), 'bumped_by_id' => $by?->id])->save();
        TicketChanged::dispatch($ticket, 'started');

        return $ticket;
    }

    public function done(Ticket $ticket, ?User $by = null): Ticket
    {
        if ($ticket->status === TicketStatus::Done) {
            return $ticket;
        }

        $ticket->forceFill([
            'status' => TicketStatus::Done,
            'started_at' => $ticket->started_at ?? now(),
            'done_at' => now(),
            'bumped_by_id' => $by?->id,
        ])->save();
        TicketChanged::dispatch($ticket, 'done');

        return $ticket;
    }

    /** New → In progress → Done (what the bump bar / number keys do). */
    public function bump(Ticket $ticket, ?User $by = null): Ticket
    {
        return $ticket->status === TicketStatus::New ? $this->start($ticket, $by) : $this->done($ticket, $by);
    }

    /** Bring a finished ticket back to the screen. */
    public function recall(Ticket $ticket, ?User $by = null): Ticket
    {
        $ticket->forceFill(['status' => TicketStatus::InProgress, 'done_at' => null, 'served_at' => null, 'bumped_by_id' => $by?->id])->save();
        TicketChanged::dispatch($ticket, 'recalled');

        return $ticket;
    }

    /** When a line is voided after sending, strike it through on the kitchen screen. */
    public function voidLine(OrderItem $line): void
    {
        $items = TicketItem::query()->where('order_item_id', $line->id)->get();
        foreach ($items as $item) {
            $item->forceFill(['is_voided' => true])->save();
            TicketChanged::dispatch($item->ticket, 'item_voided');
        }
    }

    /** @return Collection<int, Ticket> */
    /**
     * A held course was fired at the till: release those lines on their tickets and tell the screens.
     *
     * @param  array<int, string>  $orderItemIds
     * @return Collection<int, Ticket> the tickets that changed
     */
    public function fireLines(array $orderItemIds): BaseCollection
    {
        $items = TicketItem::query()->whereIn('order_item_id', $orderItemIds)->where('is_held', true)->get();
        TicketItem::query()->whereIn('id', $items->pluck('id'))->update(['is_held' => false]);

        $tickets = Ticket::query()->whereIn('id', $items->pluck('ticket_id')->unique())->get();
        foreach ($tickets as $ticket) {
            TicketChanged::dispatch($ticket, 'fired');
        }

        return $tickets;
    }

    /** Open tickets, oldest first; $stationId narrows to one station (null = every station). */
    public function activeTickets(?string $branchId = null, ?string $stationId = null): Collection
    {
        return Ticket::query()->active()->forBranch($branchId)
            ->when($stationId, fn ($q) => $q->where('station_id', $stationId))
            ->with(['items', 'station'])->orderBy('sent_at')->get();
    }

    /** @return Collection<int, Ticket> */
    public function recentlyDone(?string $branchId = null, int $limit = 8, ?string $stationId = null): Collection
    {
        return Ticket::query()->where('status', TicketStatus::Done->value)->whereNull('served_at')->forBranch($branchId)
            ->when($stationId, fn ($q) => $q->where('station_id', $stationId))
            ->with('items')->latest('done_at')->limit($limit)->get();
    }

    /**
     * "All day": how many of each item are still to be made at a station (held and voided lines excluded).
     *
     * @return BaseCollection<int, array{name: string, quantity: int}>
     */
    public function allDayCounts(?string $branchId = null, ?string $stationId = null): BaseCollection
    {
        return $this->activeTickets($branchId, $stationId)
            ->flatMap(fn (Ticket $t) => $t->items->where('is_voided', false)->where('is_held', false))
            ->groupBy('name')
            ->map(fn (BaseCollection $items, string $name) => ['name' => $name, 'quantity' => (int) $items->sum('quantity')])
            ->sortByDesc('quantity')->values();
    }

    /**
     * Expo view: every order with tickets not yet served, with its tickets grouped. An order is
     * "ready" when all its tickets are done.
     *
     * @return BaseCollection<int, array{order_number: int, tickets: Collection<int, Ticket>, ready: bool, sent_at: CarbonInterface}>
     */
    public function expoBoard(?string $branchId = null): BaseCollection
    {
        return Ticket::query()->forBranch($branchId)->whereNull('served_at')
            ->with(['items', 'station'])->orderBy('sent_at')->get()
            ->groupBy('order_id')
            ->map(fn (Collection $tickets) => [
                'order_id' => $tickets->first()->order_id,
                'order_number' => $tickets->first()->order_number,
                'where' => $tickets->first()->table_name,
                'order_type' => $tickets->first()->order_type,
                'sent_at' => $tickets->min('sent_at'),
                'tickets' => $tickets,
                'ready' => $tickets->every(fn (Ticket $t) => $t->status === TicketStatus::Done),
            ])
            ->sortBy(fn (array $o) => [$o['ready'] ? 0 : 1, $o['sent_at']])->values();
    }

    /** Expo bump: the whole order went out to the customer. Open tickets are finished first. */
    public function serve(string $orderId, ?User $by = null): int
    {
        $tickets = Ticket::query()->where('order_id', $orderId)->whereNull('served_at')->get();
        foreach ($tickets as $ticket) {
            if ($ticket->status !== TicketStatus::Done) {
                $this->done($ticket, $by);
            }
            $ticket->forceFill(['served_at' => now()])->save();
            TicketChanged::dispatch($ticket, 'served');
        }

        return $tickets->count();
    }
}
