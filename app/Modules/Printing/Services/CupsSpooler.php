<?php

namespace App\Modules\Printing\Services;

use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Exceptions\PrintFailedException;
use Symfony\Component\Process\Process;

/** Mac / Linux: `lp -d <queue> -o raw` sends the bytes untouched to a CUPS raw queue. */
class CupsSpooler implements RawSpooler
{
    public function send(string $queue, string $bytes): void
    {
        $queue = trim($queue);
        if ($queue === '' || ! preg_match('/^[A-Za-z0-9_.\-]+$/', $queue)) {
            throw new PrintFailedException(__('printing::printing.errors.bad_queue', ['queue' => $queue]));
        }

        $file = tempnam(sys_get_temp_dir(), 'rms-print-');
        file_put_contents($file, $bytes);

        try {
            $process = new Process(['lp', '-d', $queue, '-o', 'raw', '-s', $file]);
            $process->setTimeout(15)->run();

            if (! $process->isSuccessful()) {
                throw new PrintFailedException(__('printing::printing.errors.spool_failed', ['queue' => $queue, 'error' => trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'lp exit '.$process->getExitCode()]));
            }
        } finally {
            @unlink($file);
        }
    }

    public function queues(): array
    {
        $process = new Process(['lpstat', '-p']);
        $process->setTimeout(10)->run();

        $queues = [];
        foreach (preg_split('/\r?\n/', trim($process->getOutput())) as $line) {
            if (preg_match('/^printer (\S+) (.*)$/', $line, $m)) {
                $queues[] = ['name' => $m[1], 'status' => trim($m[2])];
            }
        }

        return $queues;
    }
}
