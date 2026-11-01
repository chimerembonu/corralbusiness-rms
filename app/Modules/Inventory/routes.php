<?php

use App\Modules\Inventory\Http\StockTakePrintController;
use App\Modules\Inventory\Livewire\StockTakeScreen;
use App\Modules\Pos\Http\EnsurePosUser;
use App\Support\Tenancy\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory module – web routes
|--------------------------------------------------------------------------
*/

// Counting stock on a phone or tablet (signed in with a PIN like any till screen).
Route::get('pos/stock-take', StockTakeScreen::class)->middleware(EnsurePosUser::class)->name('pos.stock-take');

// Printable count sheet (from Back office → Stock takes).
Route::get('inventory/stock-takes/{stockTake}/print', StockTakePrintController::class)->middleware(['auth', SetTenantFromUser::class])->name('inventory.stock-take.print');
