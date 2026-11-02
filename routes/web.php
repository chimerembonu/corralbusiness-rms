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

// Old address of the clock-in screen (printed on early cheat-sheets); the real screen is /clock.
Route::redirect('/clock-in', '/clock');
