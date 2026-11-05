<?php

namespace App\Modules\Printing\Documents;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\CashierSession;
use Carbon\Carbon;

/** Cash-up slip for a cashier session, and the X / Z day report, for 80 mm paper. */
final class CashReportDocument
{
    public static function cashUp(CashierSession $session): PrintDocument
    {
        $session->loadMissing(['till', 'user', 'closedBy', 'approvedBy', 'movements.user', 'branch.tenant']);
        $f = fn (string $key) => __('payments::payments.cash.fields.'.$key);
        $m = fn ($money) => money($money, false);

        $doc = PrintDocument::make(__('printing::printing.job_type.cash_up').' '.$session->displayNumber())
            ->center()->bold()->big()->text($session->branch->tenant->name)->normal()
            ->center()->text($session->branch->name)
            ->center()->bold()->tall()->text(__('printing::printing.job_type.cash_up'))->normal()
            ->rule()
            ->row($f('number'), $session->displayNumber())
            ->row($f('date'), $session->business_date->format('d/m/Y'))
            ->row($f('till'), $session->till?->name ?? __('payments::payments.cash.no_till'))
            ->row($f('cashier'), (string) $session->user?->name)
            ->row($f('opened_at'), $session->opened_at->format('H:i'))
            ->row($f('closed_at'), (string) $session->closed_at?->format('H:i'))
            ->rule()
            ->row($f('opening_float'), $m($session->opening_float))
            ->row('+ '.$f('cash_sales'), $m($session->cash_sales))
            ->row('+ '.$f('cash_tips'), $m($session->cash_tips))
            ->row('+ '.$f('pay_ins'), $m($session->pay_ins))
            ->row('- '.$f('pay_outs'), $m($session->pay_outs))
            ->row('- '.$f('cash_refunds'), $m($session->cash_refunds))
            ->bold()->row($f('expected'), $m($session->expected_cash))
            ->row($f('counted'), $m($session->counted_cash))
            ->tall()->row($f('variance'), self::signed($session->variance))->normal();

        if ($session->approvedBy) {
            $doc->row($f('approved_by'), $session->approvedBy->name);
        }

        if ($session->movements->isNotEmpty()) {
            $doc->rule()->bold()->text($f('movements'))->normal();
            foreach ($session->movements as $movement) {
                $doc->row($movement->happened_at->format('H:i').' '.$movement->type->label().': '.$movement->reason, ($movement->isPayIn() ? '+' : '-').$m($movement->amount));
            }
        }

        if ($session->totals) {
            $doc->rule()->bold()->text($f('by_method'))->normal();
            foreach ($session->totals as $row) {
                $doc->row(PaymentMethod::from($row['method'])->label().' x'.$row['count'], money($row['total'], false));
            }
        }

        if ($session->counted_breakdown) {
            $doc->rule()->bold()->text($f('count_sheet'))->normal();
            foreach ($session->counted_breakdown as $value => $count) {
                $doc->row(money((int) $value, false).' x '.$count, money((int) $value * (int) $count, false));
            }
        }
        if ($session->notes) {
            $doc->rule()->text($session->notes);
        }

        return $doc->rule()->center()->text(__('rms.product_name'))->normal()->feed(2)->cut();
    }

    /**
     * @param  array<string, mixed>  $report  from DayCloseService::report()
     */
    public static function day(array $report, string $title, string $tenantName, string $branchName, ?string $zNumber = null): PrintDocument
    {
        $t = fn (string $key) => __('pos::pos.today.'.$key);
        $m = fn (int $pesewas) => money($pesewas, false);

        $doc = PrintDocument::make($title)
            ->center()->bold()->big()->text($tenantName)->normal()
            ->center()->text($branchName)
            ->center()->bold()->tall()->text($title.($zNumber ? ' '.$zNumber : ''))->normal()
            ->rule()
            ->row(__('pos::pos.receipt.date'), Carbon::parse($report['date'])->format('d/m/Y'))
            ->row(__('pos::pos.cash.printed_at'), Carbon::parse($report['generated_at'])->format('d/m/Y H:i'))
            ->rule()
            ->bold()->row($t('gross'), $m($report['gross_sales']))->normal()
            ->row($t('net'), $m($report['net_sales']))
            ->row($t('tax'), $m($report['tax_total']));
        foreach ($report['tax_lines'] as $tax) {
            $doc->row('  '.$tax['name'], $m($tax['amount']));
        }
        $doc->row($t('discounts'), $m($report['discount_total']))
            ->row(__('orders::orders.service_charge'), $m($report['service_charge_total']))
            ->row(__('orders::orders.delivery_fee'), $m($report['delivery_fee_total']))
            ->row(__('orders::orders.tip'), $m($report['tip_total']))
            ->row($t('refunds'), $m($report['refund_total']))
            ->rule()
            ->row($t('orders'), (string) $report['orders_paid'])
            ->row($t('open'), (string) $report['orders_open'])
            ->row($t('void'), (string) $report['orders_void'])
            ->row($t('average'), $m($report['average_order']))
            ->rule()->bold()->text($t('by_type'))->normal();
        foreach ($report['by_type'] as $row) {
            $doc->row(OrderType::from($row['type'])->label().' x'.$row['count'], $m($row['total']));
        }
        $doc->rule()->bold()->text($t('by_method'))->normal();
        foreach ($report['by_payment_method'] as $row) {
            $doc->row(PaymentMethod::from($row['method'])->label().' x'.$row['count'], $m($row['total']));
        }

        $c = fn (string $key) => __('payments::payments.cash.fields.'.$key);
        $cash = $report['cash'];
        $doc->rule()->bold()->text(__('pos::pos.cash.title'))->normal()
            ->row($c('opening_float'), $m($cash['opening_float']))
            ->row('+ '.$c('cash_sales'), $m($cash['cash_sales']))
            ->row('+ '.$c('cash_tips'), $m($cash['cash_tips']))
            ->row('+ '.$c('pay_ins'), $m($cash['pay_ins']))
            ->row('- '.$c('pay_outs'), $m($cash['pay_outs']))
            ->row('- '.$c('cash_refunds'), $m($cash['cash_refunds']))
            ->bold()->row($c('expected'), $m($cash['expected']))->normal()
            ->row($c('counted'), $m($cash['counted']))
            ->row($c('variance'), self::signed($cash['variance']));

        $doc->rule()->bold()->text(__('payments::payments.cash.sessions'))->normal();
        foreach ($report['sessions'] as $s) {
            $doc->row($s['number'].' '.($s['till'] ?? '').' '.($s['cashier'] ?? ''), $s['counted'] === null ? __('payments::payments.cash.status.open') : self::signed((int) $s['variance']));
        }

        $doc->rule()->bold()->text($t('top_items'))->normal();
        foreach ($report['top_items'] as $row) {
            $doc->row($row['name'].' x'.$row['quantity'], $m($row['total']));
        }

        if (! empty($report['history_code'])) {
            $doc->rule()->row(__('settings::settings.history.z_label'), (string) $report['history_code']);
        }

        return $doc->rule()->center()->text(__('rms.product_name'))->normal()->feed(2)->cut();
    }

    /** "+GH₵ 5.00" / "-GH₵ 12.00" / "GH₵ 0.00" (no symbol on paper). */
    public static function signed(?int $pesewas, bool $withSymbol = false): string
    {
        $pesewas ??= 0;

        return ($pesewas > 0 ? '+' : ($pesewas < 0 ? '-' : '')).money(abs($pesewas), $withSymbol);
    }
}
