<?php

use App\Modules\Pos\Http\EnsurePosUser;
use App\Modules\Staff\Http\PosClockController;
use App\Modules\Staff\Http\RosterPrintController;
use App\Modules\Staff\Livewire\ClockScreen;
use App\Support\Tenancy\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Staff module – web routes
|--------------------------------------------------------------------------
*/

// The clock-in screen: any device, no login, PIN only.
Route::get('clock', ClockScreen::class)->name('staff.clock');

// Clock in / out from the till's top bar while signed in.
Route::post('pos/clock', PosClockController::class)->middleware(EnsurePosUser::class)->name('pos.clock');

// Printable week roster for managers (opens from Back office → Roster → Print).
Route::get('staff/roster/print', RosterPrintController::class)->middleware(['auth', SetTenantFromUser::class])->name('staff.roster.print');
