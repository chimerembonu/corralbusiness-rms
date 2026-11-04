<?php

namespace App\Modules\Printing\Documents;

use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

/** Builds the customer receipt for an order. */
final class ReceiptDocument
{
    public static function fromOrder(Order $order, ReceiptSettings|string|null $settings = null, bool $reprint = false, ?string $legacyFooter = null): PrintDocument
    {
        if (! $settings instanceof ReceiptSettings) {
            $settings = new ReceiptSettings(header: $settings, footer: $legacyFooter);
        }
        $order->loadMissing(['items.modifiers', 'payments', 'table', 'openedBy', 'rider', 'branch.tenant']);
        $branch = $order->branch;
        $doc = PrintDocument::make(__('printing::printing.receipt_title', ['number' => $order->displayNumber()]));

        if ($logo = $settings->logoFile()) {
            $doc->image($logo);
        }
        $doc->center()->bold()->big()->text($branch->tenant->name)->normal()
            ->center()->text($branch->name);
        if ($branch->address) {
            $doc->text($branch->address);
        }
        if ($branch->phone) {
            $doc->text($branch->phone);
        }
        if ($settings->taxNumber) {
            $doc->text(__('pos::pos.receipt.tax_number').': '.$settings->taxNumber);
        }
        foreach ($settings->headerLines() as $line) {
            $doc->text($line);
        }
        $footer = $settings->footer;
        if ($order->is_training) {
            $doc->center()->bold()->big()->text('*** '.__('pos::pos.training.receipt').' ***')->normal();
        }
        if ($reprint) {
            $doc->bold()->text('*** '.__('pos::pos.receipt.reprint').' ***')->bold(false);
        }
        $doc->normal()->rule()
            ->row(__('pos::pos.receipt.order_no'), '#'.$order->displayNumber())
            ->row(__('pos::pos.receipt.date'), ($order->paid_at ?? $order->opened_at)->format('d/m/Y H:i'))
            ->row(__('pos::pos.receipt.type'), $order->type->label().($order->table ? ' '.$order->table->name : ''));
        if ($order->openedBy && $settings->showServer) {
            $doc->row(__('pos::pos.receipt.served_by'), $order->openedBy->name);
        }
        if ($order->table) {
            $doc->row(__('pos::pos.receipt.guests'), (string) $order->covers);
        }
        if ($order->customer_name) {
            $doc->row(__('pos::pos.orders.who'), $order->customer_name);
        }
        if ($order->customer_tin) {
            $doc->row(__('pos::pos.receipt.customer_tin'), $order->customer_tin);
        }
        if ($order->isDelivery()) {
            $doc->row(__('pos::pos.delivery.phone'), (string) $order->customer_phone)->text((string) $order->delivery_address);
            if ($order->rider) {
                $doc->row(__('pos::pos.delivery.rider'), $order->rider->name);
            }
        }
        $doc->rule();

        foreach ($order->items->where('status', '!=', OrderItemStatus::Voided) as $line) {
            $doc->row("{$line->quantity} x {$line->displayName()}", money($line->line_subtotal, false));
            foreach ($line->modifiers as $modifier) {
                $doc->text('  + '.$modifier->displayName().($modifier->unit_price->isPositive() ? ' ('.money($modifier->unit_price, false).')' : ''));
            }
            if ($line->notes) {
                $doc->text('  * '.$line->notes);
            }
        }

        $doc->rule()->row(__('pos::pos.receipt.subtotal'), money($order->subtotal, false));
        if ($order->discount_total->isPositive()) {
            $doc->row(__('pos::pos.receipt.discount').($order->discountLabel() ? ' '.$order->discountLabel() : ''), '-'.money($order->discount_total, false));
        }
        foreach ($order->tax_lines ?? [] as $tax) {
            $doc->row($tax['name'].' '.rtrim(rtrim(number_format($tax['rate_basis_points'] / 100, 2), '0'), '.').'%', money($tax['amount'], false));
        }
        if ($order->service_charge->isPositive()) {
            $doc->row(__('pos::pos.receipt.service_charge').' '.rtrim(rtrim(number_format($order->service_charge_bp / 100, 2), '0'), '.').'%', money($order->service_charge, false));
        }
        if ($order->delivery_fee->isPositive()) {
            $doc->row(__('orders::orders.delivery_fee'), money($order->delivery_fee, false));
        }
        $doc->bold()->tall()->row(__('pos::pos.receipt.total'), config('rms.currency').' '.money($order->total, false))->normal()
            ->center()->text($order->prices_include_tax ? __('pos::pos.receipt.prices_include_tax') : __('pos::pos.receipt.prices_exclude_tax'))->normal()
            ->rule();

        foreach ($order->payments as $payment) {
            $label = ($payment->isRefunded() ? __('pos::pos.receipt.refund').' ' : '').$payment->methodLabel().($payment->reference ? ' '.$payment->reference : '');
            $doc->row($label, ($payment->isRefunded() ? '-' : '').money($payment->amount, false));
            if ($payment->tip->isPositive()) {
                $doc->row('  '.__('pos::pos.receipt.tip'), money($payment->tip, false));
            }
            if ($payment->tendered && $payment->tendered->greaterThan($payment->amount)) {
                $doc->row('  '.__('pos::pos.receipt.tendered'), money($payment->tendered, false))
                    ->row('  '.__('pos::pos.receipt.change'), money($payment->change_given, false));
            }
        }
        if ($order->status === OrderStatus::Refunded) {
            $doc->center()->bold()->big()->text(__('pos::pos.receipt.refunded'))->normal();
        }

        return $doc->rule()->center()->text($footer ?? __('pos::pos.receipt.default_footer'))
            ->text(__('rms.product_name'))->normal()->feed(2)->cut();
    }
}
