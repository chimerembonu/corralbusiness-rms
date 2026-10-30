<?php

namespace App\Modules\Printing\Http;

use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Services\HtmlRenderer;
use App\Modules\Printing\Services\PrintService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Serves a print job as an HTML page and lets the browser report it printed. */
class PrintJobController
{
    public function show(PrintJob $job, HtmlRenderer $renderer): Response
    {
        return response($renderer->render($job->documentObject(), $job->printer->paper_width, autoPrint: request()->boolean('print')));
    }

    /** Polled by tills: the next job waiting for a browser, if any. */
    public function next(PrintService $printing): JsonResponse
    {
        $job = $printing->nextBrowserJob(auth()->user()?->branch_id ?? Tenancy::branchId());

        return response()->json(['job' => $job ? ['id' => $job->id, 'url' => route('printing.job', [$job, 'print' => 1])] : null]);
    }

    public function printed(PrintJob $job, PrintService $printing): JsonResponse
    {
        $printing->markPrintedByBrowser($job);

        return response()->json(['ok' => true]);
    }
}
