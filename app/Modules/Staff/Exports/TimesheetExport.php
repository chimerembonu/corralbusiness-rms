<?php

namespace App\Modules\Staff\Exports;

use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Services\TimeClockService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Hours per person for a date range, one row per clock-in plus a total row per person. */
class TimesheetExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly string $from, private readonly string $to, private readonly ?string $branchId = null) {}

    public function title(): string
    {
        return __('staff::staff.timesheets.title');
    }

    public function headings(): array
    {
        $h = fn (string $k) => __('staff::staff.timesheets.columns.'.$k);

        return [$h('staff'), $h('role'), $h('date'), $h('in'), $h('out'), $h('break'), $h('hours'), $h('source'), $h('scheduled'), $h('worked'), $h('difference')];
    }

    public function array(): array
    {
        $rows = [];
        foreach (app(TimeClockService::class)->timesheet($this->from, $this->to, $this->branchId) as $row) {
            foreach ($row['entries'] as $entry) {
                /** @var TimeEntry $entry */
                $rows[] = [
                    $row['user']->name, $row['user']->rolesLabel(),
                    $entry->clocked_in_at->format('Y-m-d'), $entry->clocked_in_at->format('H:i'),
                    $entry->clocked_out_at?->format('H:i') ?? '', $entry->break_minutes,
                    round($entry->workedMinutes() / 60, 2), $entry->source->label(), '', '', '',
                ];
            }
            $rows[] = [
                $row['user']->name, $row['user']->rolesLabel(), __('staff::staff.timesheets.total'), '', '', '', '', '',
                round($row['scheduled_minutes'] / 60, 2), round($row['worked_minutes'] / 60, 2), round(($row['worked_minutes'] - $row['scheduled_minutes']) / 60, 2),
            ];
        }

        return $rows;
    }
}
