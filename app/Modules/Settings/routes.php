<?php

use App\Modules\Settings\Http\BackupDownloadController;
use App\Modules\Settings\Http\ReceiptPreviewController;
use App\Support\Tenancy\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings module – web routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', SetTenantFromUser::class])->get('receipt-preview', ReceiptPreviewController::class)->name('receipt.preview');

// Backup zip download for the back office (Phase 3.2).
Route::get('backups/download', BackupDownloadController::class)->middleware(['auth', SetTenantFromUser::class])->name('settings.backups.download');
