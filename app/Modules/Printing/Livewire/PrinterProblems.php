<?php

namespace App\Modules\Printing\Livewire;

use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\PrintService;
use App\Support\Tenancy\Tenancy;
use Livewire\Component;

/** "Printer problems" banner: failed jobs in the last hours, with a retry button. Never a crash. */
class PrinterProblems extends Component
{
    public function retryAll(PrintService $printing): void
    {
        $printing->recentFailures(Tenancy::branchId())->each(fn (PrintJob $job) => $printing->retry($job));
    }

    public function render(PrintService $printing)
    {
        return view('printing::livewire.printer-problems', ['failures' => $printing->recentFailures(Tenancy::branchId())]);
    }
}
