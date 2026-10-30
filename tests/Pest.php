<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Every test in tests/Feature and in each module's tests/ folder
| (app/Modules/<Name>/tests) gets the Laravel TestCase and a fresh SQLite
| in-memory database (see phpunit.xml).
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', dirname(__DIR__).'/app/Modules');

/*
|--------------------------------------------------------------------------
| Expectations / helpers
|--------------------------------------------------------------------------
*/

expect()->extend('toBePesewas', function () {
    return $this->toBeInt();
});
