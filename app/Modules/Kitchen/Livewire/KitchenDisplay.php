<?php

namespace App\Modules\Kitchen\Livewire;

use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Kitchen Display: live tickets, oldest first, coloured by age.
 * Updated instantly through Reverb (Echo) and every few seconds by polling as a fallback.
 * Keyboard bump bar: 1–9 bumps that ticket, Backspace/R recalls the last done ticket.
 */
#[Layout('pos::layouts.app', ['title' => 'Kitchen'])]
class KitchenDisplay extends Component
{
    public string $branchId = '';

    public ?string $notice = null;

    public function mount(): void
    {
        $this->branchId = (string) Tenancy::branchId();
    }

    #[On('echo-private:kitchen.{branchId},.ticket.changed')]
    public function refresh(): void
    {
        // Re-render; the tickets are read fresh in render().
    }

    public function bump(string $ticketId, KitchenService $kitchen): void
    {
        $this->authorizeBump();
        $kitchen->bump(Ticket::query()->findOrFail($ticketId), auth()->user());
    }

    public function start(string $ticketId, KitchenService $kitchen): void
    {
        $this->authorizeBump();
        $kitchen->start(Ticket::query()->findOrFail($ticketId), auth()->user());
    }

    public function done(string $ticketId, KitchenService $kitchen): void
    {
        $this->authorizeBump();
        $kitchen->done(Ticket::query()->findOrFail($ticketId), auth()->user());
    }

    /** Keyboard: the Nth ticket on screen (1-based). */
    public function bumpIndex(int $index, KitchenService $kitchen): void
    {
        $ticket = $kitchen->activeTickets($this->branchId)->values()->get($index - 1);
        if ($ticket) {
            $this->bump($ticket->id, $kitchen);
        }
    }

    public function recall(?string $ticketId, KitchenService $kitchen): void
    {
        $this->authorizeBump();
        $ticket = $ticketId ? Ticket::query()->findOrFail($ticketId) : $kitchen->recentlyDone($this->branchId, 1)->first();
        if ($ticket) {
            $kitchen->recall($ticket, auth()->user());
        }
    }

    private function authorizeBump(): void
    {
        abort_unless(auth()->user()->can(Permission::KitchenBump->value), 403);
    }

    public function render(KitchenService $kitchen)
    {
        return view('kitchen::livewire.kitchen-display', [
            'tickets' => $kitchen->activeTickets($this->branchId),
            'done' => $kitchen->recentlyDone($this->branchId),
            'canBump' => auth()->user()->can(Permission::KitchenBump->value),
        ]);
    }
}
