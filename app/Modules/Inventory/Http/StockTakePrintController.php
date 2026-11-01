<?php

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Models\StockTake;
use App\Modules\Staff\Enums\Permission;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** A4 count sheet with blank boxes to write in, for counting with a clipboard. */
class StockTakePrintController
{
    public function __invoke(Request $request, StockTake $stockTake): View
    {
        abort_unless($request->user()->canAny([Permission::InventoryManage->value, Permission::InventoryCount->value]), 403);

        return view('inventory::stock-take-print', ['take' => $stockTake->load('lines'), 'branch' => Tenancy::branch()]);
    }
}
