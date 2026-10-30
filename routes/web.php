<?php

use App\Support\Tenancy\StoreTenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Start screen: big buttons to every screen in the restaurant.
Route::get('/', function (StoreTenantResolver $store) {
    try {
        DB::connection()->getPdo();
        $databaseOk = true;
        $branch = $store->branch();
    } catch (Throwable) {
        $databaseOk = false;
        $branch = null;
    }

    return view('welcome', ['databaseOk' => $databaseOk, 'branch' => $branch]);
})->name('home');

// Clock-in placeholder until the Staff module screen lands in Phase 2.1.
Route::get('/clock-in', fn () => view('clock-in'))->name('clock-in');
