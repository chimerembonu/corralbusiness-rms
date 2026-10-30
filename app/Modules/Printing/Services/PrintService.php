<?php

namespace App\Modules\Printing\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Documents\ReceiptDocument;
use App\Modules\Printing\Documents\TestPageDocument;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Enums\PrintJobType;
use App\Modules\Printing\Jobs\ProcessPrintJob;
use App\Modules\Printing\Models\Printer;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Queues print jobs. Printing never blocks a till: the queue worker does the talking to printers. */
class PrintService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function enqueue(Printer $printer, PrintDocument $document, PrintJobType $type, ?Model $reference = null): PrintJob
    {
        $job = new PrintJob([
            'printer_id' => $printer->id,
            'type' => $type,
            'document' => $document->toArray(),
            'reference_type' => $reference ? $reference->getTable() : null,
            'reference_id' => $reference?->getKey(),
        ]);
        $job->branch_id = $printer->branch_id;
        $job->save();

        ProcessPrintJob::dispatch($job->id);

        return $job;
    }

    /** @return Collection<int, PrintJob> one job per receipt printer at the order's branch (none = nothing printed). */
    public function printReceipt(Order $order, bool $reprint = false): Collection
    {
        $document = ReceiptDocument::fromOrder($order, $this->settings->get('receipt.header'), $this->settings->get('receipt.footer'), $reprint);
        $printers = Printer::query()->active()->where('branch_id', $order->branch_id)->where('prints_receipts', true)->get();

        return $printers->map(fn (Printer $printer) => $this->enqueue($printer, $document, PrintJobType::Receipt, $order));
    }

    public function testPrint(Printer $printer): PrintJob
    {
        return $this->enqueue($printer, TestPageDocument::make($printer), PrintJobType::Test);
    }

    public function retry(PrintJob $job): PrintJob
    {
        $job->forceFill(['status' => PrintJobStatus::Queued, 'attempts' => 0, 'failed_at' => null])->save();
        ProcessPrintJob::dispatch($job->id);

        return $job;
    }

    /** Jobs that failed in the last hours – shown on the POS "Printer problems" banner. */
    public function recentFailures(?string $branchId = null, int $hours = 12): Collection
    {
        return PrintJob::query()->failed()->with('printer')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('failed_at', '>=', now()->subHours($hours))
            ->latest('failed_at')->get();
    }

    /** The next browser job for a till to print. */
    public function nextBrowserJob(?string $branchId = null): ?PrintJob
    {
        return PrintJob::query()->waitingBrowser()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->oldest('created_at')->first();
    }

    public function markPrintedByBrowser(PrintJob $job): PrintJob
    {
        $job->forceFill(['status' => PrintJobStatus::Printed, 'printed_at' => now()])->save();

        return $job;
    }
}
