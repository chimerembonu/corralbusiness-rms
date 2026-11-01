<?php

namespace App\Modules\Staff\Exports;

use App\Modules\Staff\Services\StaffSalesService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StaffSalesExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly string $from, private readonly string $to, private readonly ?string $branchId = null) {}

    public function title(): string
    {
        return __('staff::staff.sales.title');
    }

    public function headings(): array
    {
        $h = fn (string $k) => __('staff::staff.sales.columns.'.$k);

        return [$h('staff'), $h('role'), $h('orders'), $h('sales'), $h('average'), $h('voids'), $h('payments'), $h('taken'), $h('tips')];
    }

    public function array(): array
    {
        return app(StaffSalesService::class)->between($this->from, $this->to, $this->branchId)->map(fn (array $r) => [
            $r['user']->name, $r['user']->rolesLabel(), $r['orders'], $r['sales']->toDecimal(), $r['average']->toDecimal(),
            $r['voids'], $r['payments'], $r['taken']->toDecimal(), $r['tips']->toDecimal(),
        ])->all();
    }
}
