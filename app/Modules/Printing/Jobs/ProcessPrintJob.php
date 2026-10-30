<?php

namespace App\Modules\Printing\Jobs;

use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\PrinterDriverRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Pushes one print job to its printer; retries with growing delays, then marks it failed. */
class ProcessPrintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> seconds between retries */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly string $printJobId) {}

    public function handle(PrinterDriverRegistry $drivers): void
    {
        $job = PrintJob::query()->withoutGlobalScopes()->with('printer')->find($this->printJobId);

        if (! $job || $job->status === PrintJobStatus::Printed) {
            return;
        }

        $driver = $drivers->for($job->printer->driver);
        $job->forceFill(['status' => PrintJobStatus::Printing, 'attempts' => $job->attempts + 1])->save();

        try {
            $driver->print($job->printer, $job->documentObject());
        } catch (Throwable $e) {
            $job->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();

            if ($this->attempts() >= $this->tries) {
                $this->markFailed($job);

                return;
            }

            $job->forceFill(['status' => PrintJobStatus::Queued])->save();

            throw $e; // let the queue retry
        }

        $job->forceFill($driver->completesImmediately()
            ? ['status' => PrintJobStatus::Printed, 'printed_at' => now(), 'last_error' => null]
            : ['status' => PrintJobStatus::WaitingBrowser, 'last_error' => null]
        )->save();
    }

    public function failed(?Throwable $e): void
    {
        $job = PrintJob::query()->withoutGlobalScopes()->find($this->printJobId);
        if ($job) {
            $job->last_error = mb_substr($e?->getMessage() ?? 'Unknown error', 0, 1000);
            $this->markFailed($job);
        }
    }

    private function markFailed(PrintJob $job): void
    {
        $job->forceFill(['status' => PrintJobStatus::Failed, 'failed_at' => now()])->save();
    }
}
