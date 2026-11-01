<?php

use App\Modules\Pos\Http\EnsurePosUser;
use App\Modules\Pos\Http\ReceiptController;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrdersList;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Livewire\PinLogin;
use App\Modules\Pos\Livewire\TodaySales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pos module – web routes (touch screens)
|--------------------------------------------------------------------------
*/

Route::prefix('pos')->name('pos.')->group(function () {
    Route::get('login', PinLogin::class)->name('login');

    Route::middleware(EnsurePosUser::class)->group(function () {
        Route::get('/', OrderTypeScreen::class)->name('home');
        Route::get('orders/{order}', OrderScreen::class)->name('order');
        Route::get('orders/{order}/pay', PaymentScreen::class)->name('pay');
        Route::get('orders/{order}/receipt', ReceiptController::class)->name('receipt');
        Route::get('today', TodaySales::class)->name('today');
        Route::get('orders', OrdersList::class)->name('orders');

        Route::post('logout', function (Request $request) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('pos.login');
        })->name('logout');
    });
});
