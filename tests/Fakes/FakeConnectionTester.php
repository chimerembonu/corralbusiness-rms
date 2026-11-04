<?php

namespace Tests\Fakes;

use App\Support\Connections\ConnectionTester;
use App\Support\Connections\TestResult;

/** Passes when the secret is "good", fails otherwise — stands in for a real provider in tests. */
class FakeConnectionTester implements ConnectionTester
{
    public static array $seen = [];

    public function test(array $values): TestResult
    {
        self::$seen[] = $values;

        return ($values['secret_key'] ?? null) === 'sk_test_good_0000'
            ? TestResult::ok('Connected to the test account.')
            : TestResult::failed('The provider did not accept these keys.');
    }
}
