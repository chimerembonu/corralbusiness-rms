<?php

namespace App\Modules\Printing\Contracts;

use App\Modules\Printing\Exceptions\PrintFailedException;

/**
 * Hands raw ESC/POS bytes to a printer queue on this computer (CUPS on Mac/Linux, a shared
 * printer or COM port on Windows). Swapped for a fake in tests.
 */
interface RawSpooler
{
    /** @throws PrintFailedException */
    public function send(string $queue, string $bytes): void;

    /** @return array<int, array{name: string, status: string}> queues known to this computer */
    public function queues(): array;
}
