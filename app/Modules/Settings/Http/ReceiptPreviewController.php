<?php

namespace App\Modules\Settings\Http;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemModifier;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Services\PrintService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

/** A sample receipt with made-up lines so the owner can see the design without a real sale. */
class ReceiptPreviewController
{
    public function __invoke(PrintService $printing): View
    {
        $branch = Tenancy::branch();
        abort_unless($branch, 404);
        $branch->loadMissing('tenant');

        $order = new Order([
            'business_date' => now()->toDateString(), 'number' => 42, 'type' => OrderType::DineIn, 'covers' => 2,
            'opened_at' => now()->subMinutes(35), 'service_charge_bp' => 1000,
        ]);
        $order->status = OrderStatus::Paid;
        $order->paid_at = now();
        $order->setRelation('branch', $branch);
        $order->setRelation('table', null);
        $order->setRelation('openedBy', auth()->user());
        $order->setRelation('rider', null);

        $jollof = new OrderItem(['name' => 'Jollof Rice', 'variant_name' => 'Regular', 'quantity' => 2, 'unit_price' => 4500, 'modifiers_total' => 1500]);
        $jollof->forceFill(['line_subtotal' => 12000, 'line_total' => 14400, 'status' => 'sent']);
        $jollof->setRelation('modifiers', new Collection([new OrderItemModifier(['group_name' => 'Protein', 'name' => 'Chicken', 'unit_price' => 1500, 'quantity' => 1])]));
        $malt = new OrderItem(['name' => 'Malta Guinness', 'quantity' => 2, 'unit_price' => 1200]);
        $malt->forceFill(['line_subtotal' => 2400, 'line_total' => 2880, 'status' => 'sent']);
        $malt->setRelation('modifiers', new Collection);
        $order->setRelation('items', new Collection([$jollof, $malt]));

        $order->forceFill([
            'subtotal' => 14400, 'discount_total' => 0, 'tax_total' => 2880, 'service_charge' => 1440, 'total' => 18720, 'paid_total' => 18720,
            'tax_lines' => [
                ['code' => 'NHIL', 'name' => 'NHIL', 'rate_basis_points' => 250, 'amount' => 360],
                ['code' => 'GETFUND', 'name' => 'GETFund', 'rate_basis_points' => 250, 'amount' => 360],
                ['code' => 'VAT', 'name' => 'VAT', 'rate_basis_points' => 1500, 'amount' => 2160],
            ],
        ]);
        $cash = new Payment(['method' => 'cash', 'amount' => 18720, 'tendered' => 20000, 'change_given' => 1280, 'received_at' => now()]);
        $order->setRelation('payments', new Collection([$cash]));

        $settings = $printing->receiptSettings($branch->id);

        return view('pos::receipt', [
            'order' => $order,
            'branch' => $branch,
            'tenant' => $branch->tenant,
            'settings' => $settings,
            'header' => $settings->header,
            'footer' => $settings->footer ?? __('pos::pos.receipt.default_footer'),
            'autoPrint' => false,
            'reprint' => false,
            'preview' => true,
        ]);
    }
}
