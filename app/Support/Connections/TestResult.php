<?php

namespace App\Support\Connections;

/** What a connection test found, in words the owner understands. */
final class TestResult
{
    public function __construct(public readonly bool $ok, public readonly string $message) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
