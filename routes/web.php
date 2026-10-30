<?php

use App\Support\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    try {
        DB::connection()->getPdo();
        $databaseOk = true;
    } catch (Throwable) {
        $databaseOk = false;
    }

    return view('welcome', [
        'databaseOk' => $databaseOk,
        'modules' => Modules::enabled(),
    ]);
})->name('home');
