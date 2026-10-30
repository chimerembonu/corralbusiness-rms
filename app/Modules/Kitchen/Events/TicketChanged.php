<?php

namespace App\Modules\Kitchen\Events;

use App\Modules\Kitchen\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to kitchen screens through Reverb whenever a ticket is created or bumped.
 * Queued, so a dead WebSocket server never slows down a till (screens fall back to polling).
 */
class TicketChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Ticket $ticket, public readonly string $change) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('kitchen.'.$this->ticket->branch_id)];
    }

    public function broadcastAs(): string
    {
        return 'ticket.changed';
    }

    public function broadcastWith(): array
    {
        return ['ticket' => $this->ticket->toBroadcast(), 'change' => $this->change];
    }
}
