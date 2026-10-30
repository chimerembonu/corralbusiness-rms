<?php

use App\Modules\Pos\Http\EnsurePosUser;
use App\Modules\Printing\Http\PrintJobController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Printing module – web routes
|--------------------------------------------------------------------------
*/

Route::prefix('print')->name('printing.')->middleware(EnsurePosUser::class)->group(function () {
    Route::get('next', [PrintJobController::class, 'next'])->name('next');
    Route::get('jobs/{job}', [PrintJobController::class, 'show'])->name('job');
    Route::post('jobs/{job}/printed', [PrintJobController::class, 'printed'])->name('printed');
});
