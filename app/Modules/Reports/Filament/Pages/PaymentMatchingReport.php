<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\Payments\Gateways\GatewayReconciliationService;
use App\Modules\Reports\Filament\Pages\Concerns\HasDateRange;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Staff\Enums\Permission;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Reports → Payment matching (Complete Edition E2.5): provider payments beside the tills' records —
 * matched, missing, "needs a manager" — with the provider's own list on request, and Excel / PDF export.
 */
class PaymentMatchingReport extends Page
{
    use HasDateRange;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'reports/payment-matching';

    protected string $view = 'reports::filament.payment-matching';

    public static function getNavigationLabel(): string
    {
        return __('payments::payments.matching.title');
    }

    public function getTitle(): string
    {
        return __('payments::payments.matching.title');
    }

    public function getSubheading(): ?string
    {
        return __('payments::payments.matching.help');
    }

    public function data(): array
    {
        [$from, $to] = $this->range();

        return app(GatewayReconciliationService::class)->report($from, $to);
    }

    /** "Compare with Paystack": fetch the provider's own list in the background. */
    public function compare(string $gateway): void
    {
        abort_unless(auth()->user()?->can(Permission::ReportsView->value), 403);
        [$from, $to] = $this->range();
        $service = app(GatewayReconciliationService::class);
        if (! in_array($gateway, $service->statementGateways(), true)) {
            return;
        }
        $service->requestStatement($gateway, $from, $to);
        Notification::make()->info()->title(__('payments::payments.matching.requested', ['provider' => __('payments::payments.gateway.names.'.$gateway)]))->send();
    }

    protected function document(): ReportDocument
    {
        [$from, $to] = $this->range();
        $d = $this->data();
        $c = fn (string $k) => __('payments::payments.matching.columns.'.$k);
        $g = fn (string $k) => __('payments::payments.matching.groups.'.$k);

        $summary = array_map(fn (string $group) => [$g($group), $d['groups'][$group]['count'], $d['groups'][$group]['amount']], GatewayReconciliationService::GROUPS);
        $summary[] = [__('payments::payments.matching.totals.asked'), '', $d['totals']['asked']];
        $summary[] = [__('payments::payments.matching.totals.till'), '', $d['totals']['till']];
        if ($d['totals']['provider'] !== null) {
            $summary[] = [__('payments::payments.matching.totals.provider'), '', $d['totals']['provider']];
        }

        $rows = array_map(fn (array $r) => [$g($r['group']), $r['when'], $r['order'], $r['provider'], $r['network'], $r['phone'], $r['reference'], $r['asked'], $r['till'], $r['provider_label'], $r['note']], $this->sorted($d['rows']));
        $missing = array_map(fn (array $m) => [$m['when'], __('payments::payments.gateway.names.'.$m['gateway']), $m['reference'], $m['amount'], $m['note']], $d['missing']);

        return (new ReportDocument(__('payments::payments.matching.title'), $from, $to))
            ->section(__('payments::payments.matching.summary'), [$c('group'), '#', $c('asked')], $summary, [2])
            ->section(__('payments::payments.matching.payments'), [$c('group'), $c('when'), $c('order'), $c('provider'), $c('network'), $c('phone'), $c('reference'), $c('asked'), $c('till'), $c('provider_says'), $c('note')], $rows, [7, 8])
            ->section(__('payments::payments.matching.missing_heading'), [$c('when'), $c('provider'), $c('reference'), $c('asked'), $c('note')], $missing, [3]);
    }

    /** "Needs a manager" first, then the rest in the report's group order, each by time. */
    public function sorted(array $rows): array
    {
        $order = array_flip(GatewayReconciliationService::GROUPS);
        usort($rows, fn ($a, $b) => [$order[$a['group']], $a['when']] <=> [$order[$b['group']], $b['when']]);

        return $rows;
    }
}
