<?php

namespace App\Modules\Printing\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Printing\Documents\PrintDocument;
use App\Modules\Printing\Documents\ReceiptDocument;
use App\Modules\Printing\Documents\ReceiptSettings;
use App\Modules\Printing\Documents\TestPageDocument;
use App\Modules\Printing\Enums\PrinterDriverType;
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
        $document = ReceiptDocument::fromOrder($order, $this->receiptSettings($order->branch_id), $reprint);

        return $this->receiptPrinters($order->branch_id)->map(fn (Printer $printer) => $this->enqueue($printer, $document, PrintJobType::Receipt, $order));
    }

    /**
     * Where receipts go: the current till's own printer when this request comes from a till that
     * has one, otherwise every active receipt printer at the branch.
     *
     * @return Collection<int, Printer>
     */
    public function receiptPrinters(?string $branchId): Collection
    {
        $tillPrinter = app(CurrentTill::class)->get()?->receiptPrinter;
        if ($tillPrinter && $tillPrinter->is_active && $tillPrinter->branch_id === $branchId) {
            return new Collection([$tillPrinter]);
        }

        return Printer::query()->active()->where('branch_id', $branchId)->where('prints_receipts', true)->get();
    }

    /** Pop the cash drawer on the till's receipt printer (or any receipt printer with a drawer). */
    public function openDrawer(?string $branchId, ?Model $reference = null): ?PrintJob
    {
        $printer = $this->receiptPrinters($branchId)->first(fn (Printer $p) => $p->has_cash_drawer)
            ?? Printer::query()->active()->where('branch_id', $branchId)->where('has_cash_drawer', true)->first();

        if (! $printer || $printer->driver === PrinterDriverType::Browser) {
            return null;
        }

        return $this->enqueue($printer, PrintDocument::make(__('printing::printing.job_type.drawer'))->drawer(), PrintJobType::Drawer, $reference);
    }

    /** Header, footer, logo and tax number for receipts (Back office → Receipt design). */
    public function receiptSettings(?string $branchId = null): ReceiptSettings
    {
        return new ReceiptSettings(
            header: $this->settings->get('receipt.header', null, $branchId),
            footer: $this->settings->get('receipt.footer', null, $branchId),
            taxNumber: $this->settings->get('receipt.tax_number', null, $branchId),
            logoPath: $this->settings->get('receipt.logo_path', null, $branchId),
            showServer: (bool) $this->settings->get('receipt.show_server', true, $branchId),
        );
    }

    /** Retry every failed job at a branch. */
    public function retryFailed(?string $branchId = null): int
    {
        $failures = $this->recentFailures($branchId);
        $failures->each(fn (PrintJob $job) => $this->retry($job));

        return $failures->count();
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
