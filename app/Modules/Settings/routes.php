<?php

use App\Modules\Settings\Http\ReceiptPreviewController;
use App\Support\Tenancy\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings module – web routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', SetTenantFromUser::class])->get('receipt-preview', ReceiptPreviewController::class)->name('receipt.preview');
