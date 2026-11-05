<?php

use App\Modules\Settings\Http\BackupDownloadController;
use App\Modules\Settings\Http\ReceiptPreviewController;
use App\Modules\Settings\Http\SetupTemplateController;
use App\Modules\Settings\Livewire\SetupWizard;
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

// First-run setup wizard (Complete Edition E1.3): only open while the server has no restaurant.
Route::get('setup', SetupWizard::class)->name('setup');
Route::get('setup/templates/{type}', SetupTemplateController::class)->name('setup.template');
