<?php

namespace App\Modules\Pos\Http;

use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Open drawer" without a sale (no-sale). Needs the cash drawer permission; always written to the audit log. */
class DrawerController
{
    public function __invoke(Request $request, PrintService $printing): RedirectResponse
    {
        abort_unless($request->user()->can(Permission::CashDrawer->value), 403);

        $job = $printing->openDrawer(Tenancy::branchId());

        activity('cash_drawer')
            ->causedBy($request->user())
            ->withProperties(['reason' => 'no_sale', 'print_job_id' => $job?->id, 'till_id' => app(CurrentTill::class)->id()])
            ->log('drawer_opened');

        return back()->with($job ? 'status' : 'error', $job ? __('pos::pos.drawer.opened') : __('pos::pos.drawer.no_printer'));
    }
}
