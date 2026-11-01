<?php

namespace App\Modules\Staff\Http;

use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\TimeEntrySource;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Services\TimeClockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The "Clock in / Clock out" button in the till's top bar (clock in/out on any screen). */
class PosClockController
{
    public function __invoke(Request $request, TimeClockService $clock): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::StaffClock->value), 403);

        try {
            $result = $clock->toggle($request->user(), TimeEntrySource::Pos, app(CurrentTill::class)->id());
        } catch (TimeClockException $e) {
            return back()->with('error', $e->getMessage());
        }

        $entry = $result['entry'];

        return back()->with('status', $result['action'] === 'in'
            ? __('staff::staff.clock.pos_in', ['time' => $entry->clocked_in_at->format('H:i')])
            : __('staff::staff.clock.pos_out', ['hours' => TimeEntry::hoursLabel($entry->minutes)]));
    }
}
