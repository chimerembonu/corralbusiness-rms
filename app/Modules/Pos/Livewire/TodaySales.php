<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Staff\Enums\Permission;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Simple "Today's sales" page (the full Z-report comes in Phase 1.6). */
#[Layout('pos::layouts.app', ['title' => "Today's sales"])]
class TodaySales extends Component
{
    public string $date;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::ReportsView->value), 403);

        $this->date = now()->toDateString();
    }

    public function render(DailySummaryService $summary)
    {
        return view('pos::livewire.today-sales', ['summary' => $summary->forDate($this->date)]);
    }
}
