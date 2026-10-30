<?php

namespace App\Modules\Printing\Documents;

use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Models\Order;

/** Builds the customer receipt for an order. */
final class ReceiptDocument
{
    public static function fromOrder(Order $order, ?string $header = null, ?string $footer = null, bool $reprint = false): PrintDocument
    {
        $order->loadMissing(['items.modifiers', 'payments', 'table', 'openedBy', 'branch.tenant']);
        $branch = $order->branch;
        $doc = PrintDocument::make(__('printing::printing.receipt_title', ['number' => $order->displayNumber()]));

        $doc->center()->bold()->big()->text($branch->tenant->name)->normal()
            ->center()->text($branch->name);
        if ($branch->address) {
            $doc->text($branch->address);
        }
        if ($branch->phone) {
            $doc->text($branch->phone);
        }
        if ($header) {
            $doc->text($header);
        }
        if ($reprint) {
            $doc->bold()->text('*** '.__('pos::pos.receipt.reprint').' ***')->bold(false);
        }
        $doc->normal()->rule()
            ->row(__('pos::pos.receipt.order_no'), '#'.$order->displayNumber())
            ->row(__('pos::pos.receipt.date'), ($order->paid_at ?? $order->opened_at)->format('d/m/Y H:i'))
            ->row(__('pos::pos.receipt.type'), $order->type->label().($order->table ? ' '.$order->table->name : ''));
        if ($order->openedBy) {
            $doc->row(__('pos::pos.receipt.served_by'), $order->openedBy->name);
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
            $doc->row(__('pos::pos.receipt.discount'), '-'.money($order->discount_total, false));
        }
        foreach ($order->tax_lines ?? [] as $tax) {
            $doc->row($tax['name'].' '.rtrim(rtrim(number_format($tax['rate_basis_points'] / 100, 2), '0'), '.').'%', money($tax['amount'], false));
        }
        $doc->bold()->tall()->row(__('pos::pos.receipt.total'), config('rms.currency').' '.money($order->total, false))->normal()
            ->center()->text($order->prices_include_tax ? __('pos::pos.receipt.prices_include_tax') : __('pos::pos.receipt.prices_exclude_tax'))->normal()
            ->rule();

        foreach ($order->payments as $payment) {
            $doc->row($payment->method->label().($payment->reference ? ' '.$payment->reference : ''), money($payment->amount, false));
            if ($payment->tendered && $payment->tendered->greaterThan($payment->amount)) {
                $doc->row('  '.__('pos::pos.receipt.tendered'), money($payment->tendered, false))
                    ->row('  '.__('pos::pos.receipt.change'), money($payment->change_given, false));
            }
        }

        return $doc->rule()->center()->text($footer ?? __('pos::pos.receipt.default_footer'))
            ->text(__('rms.product_name'))->normal()->feed(2)->cut();
    }
}
