<?php

namespace App\Modules\Pos\Http;

use App\Modules\Orders\Models\Order;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Contracts\View\View;

/** HTML receipt sized for 80 mm thermal paper (browser print fallback, CLAUDE.md §2). */
class ReceiptController
{
    public function __invoke(Order $order, SettingsService $settings): View
    {
        $order->load(['items.modifiers', 'payments', 'table', 'openedBy', 'branch.tenant']);

        return view('pos::receipt', [
            'order' => $order,
            'branch' => $order->branch,
            'tenant' => $order->branch->tenant,
            'header' => $settings->get('receipt.header'),
            'footer' => $settings->get('receipt.footer', __('pos::pos.receipt.default_footer')),
            'autoPrint' => request()->boolean('print'),
        ]);
    }
}
