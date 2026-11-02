<?php

namespace App\Modules\Pos\Http;

use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Settings\Services\SettingsService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;

/** HTML receipt sized for 80 mm thermal paper (browser print fallback, CLAUDE.md §2). */
class ReceiptController
{
    public function __invoke(Order $order, SettingsService $settings, PrintService $printing): View
    {
        Tenancy::assertOwns($order);
        $order->load(['items.modifiers', 'payments', 'table', 'openedBy', 'rider', 'branch.tenant']);

        return view('pos::receipt', [
            'order' => $order,
            'branch' => $order->branch,
            'tenant' => $order->branch->tenant,
            'settings' => $receipt = $printing->receiptSettings($order->branch_id),
            'header' => $receipt->header,
            'footer' => $receipt->footer ?? __('pos::pos.receipt.default_footer'),
            'autoPrint' => request()->boolean('print'),
            'reprint' => request()->boolean('reprint') || ($order->isPaid() && $order->paid_at?->lt(now()->subMinutes(2))),
        ]);
    }
}
