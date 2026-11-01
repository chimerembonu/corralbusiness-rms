<?php

namespace App\Modules\Pos\Http;

use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Printable (80 mm) X / Z report and cash-up slip pages. */
class CashReportController
{
    /** X report of a day, or the Z report once that day is closed. */
    public function day(Request $request, DayCloseService $days): View
    {
        abort_unless($request->user()->can(Permission::ReportsView->value), 403);

        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $close = $days->closedDay($date);

        return view('pos::cash-report', [
            'report' => $close?->report ?? $days->report($date),
            'close' => $close,
            'branch' => Tenancy::branch(),
            'tenant' => Tenancy::branch()?->tenant,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    public function session(Request $request, CashierSession $session, CashService $cash): View
    {
        abort_unless($session->user_id === $request->user()->id || $request->user()->can(Permission::ReportsView->value), 403);

        $session->load(['till', 'user', 'closedBy', 'approvedBy', 'movements.user', 'branch.tenant']);

        return view('pos::cash-slip', [
            'session' => $session,
            'expected' => $session->isOpen() ? $cash->expected($session) : $session->expected_cash,
            'autoPrint' => $request->boolean('print'),
        ]);
    }
}
