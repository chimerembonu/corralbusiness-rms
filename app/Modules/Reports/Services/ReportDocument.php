<?php

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Exports\TableExport;
use App\Modules\Reports\Exports\WorkbookExport;
use App\Support\Tenancy\Tenancy;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report ready to export: a title, a period and sections of (headings, rows).
 * Money cells are ints in pesewas and are formatted per output; mark their column indexes in $moneyColumns.
 */
final class ReportDocument
{
    /** @var array<int, array{title: string, headings: array<int, string>, rows: array<int, array<int, mixed>>, money: array<int, int>, totals?: array<int, mixed>}> */
    private array $sections = [];

    public function __construct(public readonly string $title, public readonly string $from, public readonly string $to) {}

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $money  column indexes holding pesewas
     * @param  array<int, mixed>|null  $totals  optional total row (pesewas in money columns)
     */
    public function section(string $title, array $headings, array $rows, array $money = [], ?array $totals = null): self
    {
        $this->sections[] = ['title' => $title, 'headings' => $headings, 'rows' => array_values($rows), 'money' => $money, 'totals' => $totals];

        return $this;
    }

    public function sections(): array
    {
        return $this->sections;
    }

    public function fileName(string $extension): string
    {
        return str($this->title)->slug().'-'.$this->from.'-to-'.$this->to.'.'.$extension;
    }

    public function excel(): BinaryFileResponse
    {
        $sheets = [];
        foreach ($this->sections as $section) {
            $rows = array_map(fn (array $row) => $this->formatRow($row, $section['money'], fn (int $p) => ReportService::decimal($p)), $section['rows']);
            if ($section['totals']) {
                $rows[] = $this->formatRow($section['totals'], $section['money'], fn (int $p) => ReportService::decimal($p));
            }
            $sheets[] = new TableExport($section['title'], $section['headings'], $rows);
        }

        return Excel::download(new WorkbookExport($sheets), $this->fileName('xlsx'));
    }

    public function pdf(): StreamedResponse
    {
        $sections = array_map(fn (array $section) => [
            'title' => $section['title'],
            'headings' => $section['headings'],
            'money' => $section['money'],
            'rows' => array_map(fn (array $row) => $this->formatRow($row, $section['money'], fn (int $p) => money($p, false)), $section['rows']),
            'totals' => $section['totals'] ? $this->formatRow($section['totals'], $section['money'], fn (int $p) => money($p, false)) : null,
        ], $this->sections);

        $pdf = Pdf::loadView('reports::pdf.report', [
            'title' => $this->title,
            'from' => $this->from,
            'to' => $this->to,
            'sections' => $sections,
            'branch' => Tenancy::branch(),
            'tenant' => Tenancy::branch()?->tenant,
        ])->setPaper('a4');

        // Streamed so the download also works from a Livewire (Filament) action.
        return response()->streamDownload(fn () => print $pdf->output(), $this->fileName('pdf'), ['Content-Type' => 'application/pdf']);
    }

    /** @param  array<int, mixed>  $row */
    private function formatRow(array $row, array $money, callable $format): array
    {
        foreach ($money as $index) {
            if (array_key_exists($index, $row) && is_int($row[$index])) {
                $row[$index] = $format($row[$index]);
            }
        }

        return array_values($row);
    }
}
