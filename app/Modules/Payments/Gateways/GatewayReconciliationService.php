<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Jobs\FetchGatewayStatement;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Payments\Models\GatewayTransaction;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Payment matching report (Complete Edition E2.5): every payment taken through a provider in a period, beside
 * what the till recorded and — when fetched — what the provider itself says. Each payment lands in one group:
 * matched, refunded, needs a manager, not paid, or still waiting; plus payments the provider has that no till
 * knows about ("missing on the till").
 *
 * Works offline from the app's own records. The provider's list is fetched only when a manager asks, in a
 * background job, and kept for a day.
 */
class GatewayReconciliationService
{
    public const GROUPS = ['needs_manager', 'missing', 'matched', 'refunded', 'waiting', 'not_paid'];

    private const STATEMENT_HOURS = 24;

    public function __construct(private readonly GatewayManager $gateways) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, missing: array<int, array<string, mixed>>, groups: array<string, array{count: int, amount: int}>,
     *     totals: array{asked: int, till: int, provider: ?int}, statements: array<string, array<string, mixed>>}
     */
    public function report(string $from, string $to, ?string $branchId = null): array
    {
        [$start, $end] = self::period($from, $to);
        $branchId ??= Tenancy::branchId();

        $transactions = GatewayTransaction::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_training', false)
            ->whereBetween('created_at', [$start, $end])
            ->with(['order', 'payment'])
            ->orderBy('created_at')
            ->get();
        $refunds = GatewayRefund::query()->whereIn('payment_id', $transactions->pluck('payment_id')->filter())->get()->keyBy('payment_id');

        $statements = [];
        foreach ($transactions->pluck('gateway')->merge($this->statementGateways())->unique() as $gateway) {
            $statements[$gateway] = $this->statementInfo($gateway, $from, $to);
        }

        $rows = [];
        foreach ($transactions as $t) {
            $statement = $statements[$t->gateway]['entries'] ?? null;
            $provider = $statement === null ? null : ($statement[strtolower($t->provider_reference ?? $t->reference)] ?? $statement[strtolower($t->reference)] ?? false);
            $rows[] = $this->row($t, $refunds->get($t->payment_id), $provider);
        }

        // The provider has payments of ours (our "RMS…" references) that no till knows about.
        // (Any branch of this restaurant, and a day either side: a prompt sent just before midnight is not "missing".)
        $known = GatewayTransaction::query()->withoutGlobalScopes()->where('tenant_id', Tenancy::tenantId())
            ->whereBetween('created_at', [$start->subDay(), $end->addDay()])->get(['reference', 'provider_reference'])
            ->flatMap(fn ($t) => [$t->reference, $t->provider_reference])->filter()->map(fn ($r) => strtolower($r))->flip();
        $missing = [];
        foreach ($statements as $gateway => $info) {
            foreach ($info['entries'] ?? [] as $reference => $entry) {
                if ($entry['status'] === 'paid' && str_starts_with($reference, 'rms') && ! isset($known[$reference])) {
                    $missing[] = [
                        'when' => $entry['paid_at'] ? CarbonImmutable::parse($entry['paid_at'])->setTimezone(config('app.timezone'))->format('d/m H:i') : '—',
                        'gateway' => $gateway, 'reference' => $entry['reference'], 'amount' => $entry['amount'],
                        'note' => __('payments::payments.matching.notes.missing', ['provider' => $this->name($gateway)]),
                    ];
                }
            }
        }

        $groups = array_fill_keys(self::GROUPS, ['count' => 0, 'amount' => 0]);
        foreach ($rows as $r) {
            $groups[$r['group']]['count']++;
            $groups[$r['group']]['amount'] += $r['asked'];
        }
        foreach ($missing as $m) {
            $groups['missing']['count']++;
            $groups['missing']['amount'] += $m['amount'];
        }

        $fetched = collect($statements)->filter(fn ($s) => isset($s['entries']));

        return [
            'rows' => $rows,
            'missing' => $missing,
            'groups' => $groups,
            'totals' => [
                'asked' => (int) collect($rows)->whereIn('group', ['matched', 'refunded', 'needs_manager'])->sum('asked'),
                'till' => (int) collect($rows)->sum('till'),
                // What the provider says it received for our payments in the period (only when its list was fetched).
                'provider' => $fetched->isEmpty() ? null : (int) collect($rows)->sum(fn ($r) => ($r['provider_status'] ?? null) === 'paid' || ($r['provider_status'] ?? null) === 'reversed' ? $r['provider_amount'] : 0)
                    + (int) collect($missing)->sum('amount'),
            ],
            'statements' => collect($statements)->map(fn ($s) => collect($s)->except('entries')->all())->all(),
        ];
    }

    /** Ask the provider for its list of payments in the background; the report shows it when it arrives. */
    public function requestStatement(string $gateway, string $from, string $to): void
    {
        if (! in_array($gateway, $this->statementGateways(), true)) {
            return;
        }
        Cache::put($this->key($gateway, $from, $to), ['status' => 'fetching', 'requested_at' => now()->toIso8601String()], now()->addHours(self::STATEMENT_HOURS));
        FetchGatewayStatement::dispatch((string) Tenancy::tenantId(), Tenancy::branchId(), $gateway, $from, $to);
    }

    /** Run by FetchGatewayStatement. */
    public function fetchStatement(string $gateway, string $from, string $to): void
    {
        [$start, $end] = self::period($from, $to);
        try {
            $entries = [];
            foreach ($this->gateways->make($gateway)->statement($start, $end) as $entry) {
                $entries[strtolower($entry['reference'])] = $entry;
            }
            Cache::put($this->key($gateway, $from, $to), ['status' => 'ready', 'fetched_at' => now()->toIso8601String(), 'count' => count($entries), 'entries' => $entries], now()->addHours(self::STATEMENT_HOURS));
        } catch (Throwable $e) {
            report($e);
            Cache::put($this->key($gateway, $from, $to), ['status' => 'failed', 'fetched_at' => now()->toIso8601String(),
                'error' => __('payments::payments.matching.fetch_failed', ['provider' => $this->name($gateway)])], now()->addHours(self::STATEMENT_HOURS));
        }
    }

    /** Providers that are set up and can list their payments. @return array<int, string> */
    public function statementGateways(): array
    {
        return array_values(array_filter($this->gateways->ready(), function (string $key) {
            try {
                return $key !== GatewayManager::DEMO && $this->gateways->make($key)->supports(GatewayCapability::Statement);
            } catch (Throwable) {
                return false;
            }
        }));
    }

    /** One payment, sorted into a group with a plain-English note. $provider: the provider's entry, false = not on its list, null = list not fetched. */
    private function row(GatewayTransaction $t, ?GatewayRefund $refund, array|false|null $provider): array
    {
        $asked = $t->amount->add($t->tip)->amount;
        $payment = $t->payment;
        $till = $payment && $payment->status === PaymentStatus::Completed ? $payment->amount->add($payment->tip)->amount : 0;
        $name = $this->name($t->gateway);
        $n = fn (string $key, array $r = []) => __('payments::payments.matching.notes.'.$key, $r + ['provider' => $name]);

        [$group, $note] = match (true) {
            $t->status === GatewayStatus::NeedsAttention => ['needs_manager', $n('not_on_order')],
            $payment !== null && $payment->status === PaymentStatus::Refunded => match (true) {
                $refund === null => ['needs_manager', $n('refunded_not_sent')],
                $refund->status->needsHand() => ['needs_manager', $n('refund_by_hand', ['amount' => money($refund->amount)])],
                $refund->status->isFinal() => ['refunded', $refund->status->label()],
                default => ['refunded', $n('refund_on_way')],
            },
            $payment !== null && $payment->amount->add($payment->tip)->amount !== $asked => ['needs_manager', $n('amount_differs')],
            $payment !== null => ['matched', $n('matched')],
            in_array($t->status, [GatewayStatus::Pending, GatewayStatus::NeedsOtp], true) => ['waiting', $n('waiting')],
            default => ['not_paid', $t->message ?: $t->status->label()],
        };

        // The provider's own list, when fetched, can overrule: money it has that the till has not, and the reverse.
        if (is_array($provider)) {
            if ($provider['status'] === 'paid' && $payment === null && $t->status !== GatewayStatus::NeedsAttention) {
                [$group, $note] = ['needs_manager', $n('paid_not_recorded')];
            } elseif ($payment !== null && $group === 'matched' && $provider['status'] !== 'paid') {
                [$group, $note] = ['needs_manager', $n('provider_not_paid', ['status' => __('payments::payments.matching.provider_status.'.$provider['status'])])];
            } elseif ($payment !== null && in_array($provider['status'], ['paid', 'reversed'], true) && (int) $provider['amount'] !== $asked) {
                [$group, $note] = ['needs_manager', $n('provider_amount_differs', ['amount' => money((int) $provider['amount'])])];
            }
        } elseif ($provider === false && $payment !== null && $group === 'matched') {
            [$group, $note] = ['needs_manager', $n('not_on_provider_list')];
        }

        return [
            'when' => $t->created_at->format('d/m H:i'),
            'order' => $t->order ? '#'.$t->order->displayNumber() : '—',
            'gateway' => $t->gateway,
            'provider' => $name,
            'network' => $t->network?->label() ?? '—',
            'phone' => $t->phone_masked ?? '—',
            'reference' => $t->provider_reference ?? $t->reference,
            'asked' => $asked,
            'till' => $till,
            'provider_status' => is_array($provider) ? $provider['status'] : null,
            'provider_amount' => is_array($provider) ? (int) $provider['amount'] : null,
            'provider_label' => match (true) {
                is_array($provider) => __('payments::payments.matching.provider_status.'.$provider['status']).' · '.money((int) $provider['amount']),
                $provider === false => __('payments::payments.matching.provider_status.none'),
                default => '—',
            },
            'refund' => $refund ? $refund->status->label() : null,
            'group' => $group,
            'note' => $note,
        ];
    }

    /** @return array<string, mixed> status (none|fetching|ready|failed|unsupported), fetched_at, count, error, entries */
    private function statementInfo(string $gateway, string $from, string $to): array
    {
        if (! in_array($gateway, $this->statementGateways(), true)) {
            return ['status' => 'unsupported', 'name' => $this->name($gateway)];
        }

        return ['name' => $this->name($gateway)] + (Cache::get($this->key($gateway, $from, $to)) ?? ['status' => 'none']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the whole days in the restaurant's time zone */
    public static function period(string $from, string $to): array
    {
        $tz = config('app.timezone');

        return [CarbonImmutable::parse($from, $tz)->startOfDay(), CarbonImmutable::parse($to, $tz)->endOfDay()];
    }

    private function key(string $gateway, string $from, string $to): string
    {
        return 'rms:statement:'.Tenancy::tenantId().':'.$gateway.':'.$from.':'.$to;
    }

    private function name(string $gateway): string
    {
        return __('payments::payments.gateway.names.'.$gateway);
    }
}
