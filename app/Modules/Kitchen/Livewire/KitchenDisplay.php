<?php

namespace App\Modules\Kitchen\Livewire;

use App\Modules\Kitchen\Models\Station;
use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kitchen Display: live tickets for one station (or all), oldest first, coloured by age,
 * with an "all day" panel of outstanding items. Updated instantly through Reverb (Echo) and
 * every few seconds by polling as a fallback. Keyboard: 1–9 bumps that ticket, Backspace/R recalls.
 */
#[Layout('pos::layouts.app', ['title' => 'Kitchen'])]
class KitchenDisplay extends Component
{
    public string $branchId = '';

    /** Station code from the address bar, e.g. /kitchen?station=GRILL. Empty = all stations. */
    #[Url(as: 'station')]
    public string $stationCode = '';

    public bool $showAllDay = true;

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

    public function selectStation(string $code): void
    {
        $this->stationCode = strtoupper($code);
    }

    public function toggleAllDay(): void
    {
        $this->showAllDay = ! $this->showAllDay;
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
        $ticket = $kitchen->activeTickets($this->branchId, $this->stationId())->values()->get($index - 1);
        if ($ticket) {
            $this->bump($ticket->id, $kitchen);
        }
    }

    public function recall(?string $ticketId, KitchenService $kitchen): void
    {
        $this->authorizeBump();
        $ticket = $ticketId ? Ticket::query()->findOrFail($ticketId) : $kitchen->recentlyDone($this->branchId, 1, $this->stationId())->first();
        if ($ticket) {
            $kitchen->recall($ticket, auth()->user());
        }
    }

    private function stationId(): ?string
    {
        return $this->stationCode === '' ? null : $this->station()?->id;
    }

    private function station(): ?Station
    {
        return $this->stationCode === '' ? null : Station::query()->forBranch($this->branchId)->where('code', $this->stationCode)->first();
    }

    private function authorizeBump(): void
    {
        abort_unless(auth()->user()->can(Permission::KitchenBump->value), 403);
    }

    public function render(KitchenService $kitchen)
    {
        $stationId = $this->stationId();

        return view('kitchen::livewire.kitchen-display', [
            'stations' => Station::query()->forBranch($this->branchId)->active()->ordered()->get(),
            'station' => $this->station(),
            'tickets' => $kitchen->activeTickets($this->branchId, $stationId),
            'done' => $kitchen->recentlyDone($this->branchId, 8, $stationId),
            'allDay' => $this->showAllDay ? $kitchen->allDayCounts($this->branchId, $stationId) : collect(),
            'canBump' => auth()->user()->can(Permission::KitchenBump->value),
        ]);
    }
}
