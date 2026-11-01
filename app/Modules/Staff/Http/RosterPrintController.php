<?php

namespace App\Modules\Staff\Http;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Services\RosterService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** A printable week (A4 landscape) to pin up in the kitchen. */
class RosterPrintController
{
    public function __invoke(Request $request, RosterService $roster): View
    {
        abort_unless($request->user()->can(Permission::StaffManage->value), 403);

        $start = RosterService::weekStart($request->string('week')->toString() ?: null);
        $days = collect(range(0, 6))->map(fn (int $i) => $start->addDays($i));

        return view('staff::roster-print', [
            'start' => $start,
            'days' => $days,
            'staff' => $roster->staff(),
            'shifts' => $roster->week($start)->groupBy(fn (RosterShift $s) => $s->user_id.'|'.$s->shift_date->toDateString()),
            'branch' => Tenancy::branch(),
        ]);
    }
}
