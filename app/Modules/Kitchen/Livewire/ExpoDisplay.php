<?php

namespace App\Modules\Kitchen\Livewire;

use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/** Expo / pass screen: one card per order with every station's progress; "Served" clears it. */
#[Layout('pos::layouts.app', ['title' => 'Expo'])]
class ExpoDisplay extends Component
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
        //
    }

    public function serve(string $orderId, KitchenService $kitchen): void
    {
        abort_unless(auth()->user()->can(Permission::KitchenBump->value), 403);
        $kitchen->serve($orderId, auth()->user());
    }

    public function bumpTicket(string $ticketId, KitchenService $kitchen): void
    {
        abort_unless(auth()->user()->can(Permission::KitchenBump->value), 403);
        $kitchen->bump(Ticket::query()->findOrFail($ticketId), auth()->user());
    }

    public function render(KitchenService $kitchen)
    {
        return view('kitchen::livewire.expo-display', [
            'orders' => $kitchen->expoBoard($this->branchId),
            'canBump' => auth()->user()->can(Permission::KitchenBump->value),
        ]);
    }
}
