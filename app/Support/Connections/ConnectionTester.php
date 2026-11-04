<?php

namespace App\Support\Connections;

/** Checks a connection's details against the real service (e.g. a harmless "who am I" call). Never charges money. */
interface ConnectionTester
{
    /** @param  array<string, string|null>  $values  the saved details, secrets already decrypted */
    public function test(array $values): TestResult;
}
