<?php

use App\Modules\Customers\Http\StatementController;
use App\Modules\Customers\Livewire\CustomersScreen;
use App\Modules\Pos\Http\EnsurePosUser;
use App\Support\Tenancy\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customers module – web routes
|--------------------------------------------------------------------------
*/

Route::get('pos/customers', CustomersScreen::class)->middleware(EnsurePosUser::class)->name('pos.customers');

// Printable account statement (from Back office → Customers, or the till).
Route::get('customers/{customer}/statement', StatementController::class)->middleware(['auth', SetTenantFromUser::class])->name('customers.statement');
