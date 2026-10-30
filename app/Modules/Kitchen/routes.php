<?php

use App\Modules\Kitchen\Http\EnsureKitchenUser;
use App\Modules\Kitchen\Livewire\KitchenDisplay;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kitchen module – web routes
|--------------------------------------------------------------------------
*/

Route::middleware(EnsureKitchenUser::class)->group(function () {
    Route::get('kitchen', KitchenDisplay::class)->name('kitchen.display');
});
