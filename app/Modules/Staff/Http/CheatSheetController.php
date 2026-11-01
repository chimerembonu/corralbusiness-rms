<?php

namespace App\Modules\Staff\Http;

use App\Modules\Kitchen\Models\Station;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * The staff cheat-sheet: one page per role with the real addresses, tills and stations of this
 * restaurant. HTML to read on screen, ?pdf=1 to download and pin up.
 */
class CheatSheetController
{
    public function __invoke(Request $request)
    {
        abort_unless($request->user()->can(Permission::StaffManage->value), 403);

        $data = [
            'tenant' => Tenancy::branch()?->tenant,
            'branch' => Tenancy::branch(),
            'base' => rtrim(config('app.url'), '/'),
            'tills' => Till::query()->active()->orderBy('code')->pluck('name')->all(),
            'stations' => Station::query()->where('is_active', true)->orderBy('sort_order')->pluck('name')->all(),
            'sections' => __('staff::staff.cheat_sheet.sections'),
            'printed' => now()->format('d/m/Y'),
        ];

        if ($request->boolean('pdf')) {
            return Pdf::loadView('staff::cheat-sheet', $data + ['pdf' => true])->setPaper('a4')->download('corralbusiness-staff-cheat-sheet.pdf');
        }

        return view('staff::cheat-sheet', $data + ['pdf' => false]);
    }
}
