<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Exceptions\PrintFailedException;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Throwable;

/** Windows: a shared printer (\\computer\printer), an LPT or a COM port. */
class WindowsSpooler implements RawSpooler
{
    public function send(string $queue, string $bytes): void
    {
        try {
            $connector = new WindowsPrintConnector(trim($queue));
            $connector->write($bytes);
            $connector->finalize();
        } catch (Throwable $e) {
            throw new PrintFailedException(__('printing::printing.errors.spool_failed', ['queue' => $queue, 'error' => $e->getMessage()]), 0, $e);
        }
    }

    public function queues(): array
    {
        return [];
    }
}
