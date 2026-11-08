<?php

use App\Modules\Settings\Http\BackupDownloadController;
use App\Modules\Settings\Http\DeviceSetupController;
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

// HTTPS on the restaurant network (Complete Edition E9.1): each device trusts the restaurant's certificate
// once. Reachable over plain http on purpose (before the device trusts the certificate); no login, nothing secret.
Route::get('device-setup', [DeviceSetupController::class, 'show'])->name('device-setup');
Route::get('device-setup/certificate', [DeviceSetupController::class, 'certificate'])->name('device-setup.certificate');
