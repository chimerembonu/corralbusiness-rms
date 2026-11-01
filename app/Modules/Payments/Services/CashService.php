<?php

namespace App\Modules\Payments\Services;

use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Enums\CashierSessionStatus;
use App\Modules\Payments\Enums\CashMovementType;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\CashierSessionClosed;
use App\Modules\Payments\Events\CashierSessionOpened;
use App\Modules\Payments\Events\CashMovementRecorded;
use App\Modules\Payments\Exceptions\CashException;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Models\CashMovement;
use App\Modules\Payments\Models\Payment;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Settings\Services\CounterService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cashier sessions: a cashier opens a till with a counted float, takes cash, records pay-ins and
 * pay-outs, and counts the drawer at the end (cash-up). Payments taken meanwhile link to the session.
 */
class CashService
{
    /** Ghana cedi notes and coins, in pesewas, largest first (count sheet at cash-up). */
    public const DENOMINATIONS = [20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5];

    public function __construct(
        private readonly CounterService $counters,
        private readonly TenantContext $context,
        private readonly TillSettings $settings,
    ) {}

    /**
     * The session cash goes into right now: the open session on this device's till, otherwise the
     * open session the user opened themself (a tablet without a till), otherwise null.
     */
    public function current(?User $user = null, ?string $branchId = null): ?CashierSession
    {
        $branchId ??= $this->context->branchId();

        if ($tillId = app(CurrentTill::class)->id()) {
            $session = CashierSession::query()->open()->with(['till', 'user'])->where('branch_id', $branchId)->where('till_id', $tillId)->first();
            if ($session) {
                return $session;
            }
        }

        if ($user) {
            return CashierSession::query()->open()->with(['till', 'user'])->where('branch_id', $branchId)->where('user_id', $user->id)->latest('opened_at')->first();
        }

        return null;
    }

    /** @return Collection<int, CashierSession> every open session at the branch, oldest first */
    public function openSessions(?string $branchId = null): Collection
    {
        return CashierSession::query()->open()->with(['till', 'user'])
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->orderBy('opened_at')->get();
    }

    /** @return Collection<int, CashierSession> sessions of a business date, open ones first */
    public function forDate(string $date, ?string $branchId = null): Collection
    {
        return CashierSession::query()->with(['till', 'user', 'closedBy'])
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->whereDate('business_date', $date)
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->orderBy('opened_at')->get();
    }

    /** Open a till: the cashier counts the float in the drawer. One open session per till. */
    public function open(?Till $till, User $cashier, Money $float, string $businessDate): CashierSession
    {
        if (! $cashier->can(Permission::PaymentsTake->value)) {
            throw new ManagerPinRequiredException(Permission::PaymentsTake->value);
        }
        if ($float->isNegative()) {
            throw new CashException(__('payments::payments.cash.errors.float_invalid'));
        }

        $branchId = $till?->branch_id ?? $this->context->branchId();

        if ($till && CashierSession::query()->open()->where('till_id', $till->id)->exists()) {
            throw new CashException(__('payments::payments.cash.errors.till_already_open', ['till' => $till->name]));
        }
        if (! $till && CashierSession::query()->open()->where('branch_id', $branchId)->where('user_id', $cashier->id)->exists()) {
            throw new CashException(__('payments::payments.cash.errors.already_open'));
        }

        return DB::transaction(function () use ($till, $cashier, $float, $businessDate, $branchId) {
            $session = new CashierSession([
                'number' => $this->counters->next('cashier_sessions', null, $branchId),
                'business_date' => $businessDate,
                'till_id' => $till?->id,
                'user_id' => $cashier->id,
                'opening_float' => $float,
                'opened_at' => now(),
            ]);
            $session->branch_id = $branchId;
            $session->save();

            CashierSessionOpened::dispatch($session, $cashier->id);

            return $session;
        });
    }

    public function payIn(CashierSession $session, Money $amount, string $reason, User $by, ?string $reference = null): CashMovement
    {
        return $this->move($session, CashMovementType::PayIn, $amount, $reason, $by, $reference);
    }

    public function payOut(CashierSession $session, Money $amount, string $reason, User $by, ?string $reference = null): CashMovement
    {
        if ($amount->greaterThan($this->expected($session))) {
            throw new CashException(__('payments::payments.cash.errors.not_enough_cash', ['cash' => money($this->expected($session))]));
        }

        return $this->move($session, CashMovementType::PayOut, $amount, $reason, $by, $reference);
    }

    private function move(CashierSession $session, CashMovementType $type, Money $amount, string $reason, User $by, ?string $reference): CashMovement
    {
        $this->assertOpen($session);
        if (! $by->can(Permission::CashDrawer->value)) {
            throw new ManagerPinRequiredException(Permission::CashDrawer->value);
        }
        if (! $amount->isPositive()) {
            throw new CashException(__('payments::payments.errors.amount_required'));
        }
        if (blank($reason)) {
            throw new CashException(__('orders::orders.errors.reason_required'));
        }

        return DB::transaction(function () use ($session, $type, $amount, $reason, $by, $reference) {
            $movement = new CashMovement([
                'cashier_session_id' => $session->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => trim($reason),
                'reference' => filled($reference) ? trim($reference) : null,
                'user_id' => $by->id,
                'happened_at' => now(),
            ]);
            $movement->branch_id = $session->branch_id;
            $movement->save();

            $this->recalculate($session);
            CashMovementRecorded::dispatch($movement, $by->id);

            return $movement;
        });
    }

    /** What should be in the drawer: float + cash sales + cash tips + pay-ins − pay-outs − cash refunds. */
    public function expected(CashierSession $session): Money
    {
        $this->recalculate($session);

        return $session->opening_float
            ->add($session->cash_sales)->add($session->cash_tips)->add($session->pay_ins)
            ->subtract($session->pay_outs)->subtract($session->cash_refunds);
    }

    /**
     * Cash-up: the cashier counts the drawer and the session closes. A variance beyond the tolerance
     * needs a manager ($approvedBy with the cash-variance permission).
     *
     * @param  array<int|string, int>  $breakdown  note value (pesewas) → how many
     */
    public function close(CashierSession $session, Money $counted, User $by, array $breakdown = [], ?string $notes = null, ?User $approvedBy = null): CashierSession
    {
        $this->assertOpen($session);
        if ($counted->isNegative()) {
            throw new CashException(__('payments::payments.cash.errors.count_invalid'));
        }

        $expected = $this->expected($session);
        $variance = $counted->amount - $expected->amount;

        if (abs($variance) > $this->settings->varianceTolerance($session->branch_id)) {
            if (! $approvedBy?->can(Permission::CashVariance->value)) {
                throw new ManagerPinRequiredException(Permission::CashVariance->value);
            }
        } else {
            $approvedBy = null;
        }

        return DB::transaction(function () use ($session, $counted, $by, $breakdown, $notes, $approvedBy, $expected, $variance) {
            $session->forceFill([
                'status' => CashierSessionStatus::Closed,
                'closed_at' => now(),
                'closed_by_id' => $by->id,
                'approved_by_id' => $approvedBy?->id,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => $variance,
                'counted_breakdown' => $breakdown === [] ? null : array_filter(array_map('intval', $breakdown)),
                'totals' => $this->totalsByMethod($session),
                'notes' => filled($notes) ? trim($notes) : null,
            ])->save();

            CashierSessionClosed::dispatch($session, $by->id);

            return $session;
        });
    }

    /** Refresh the running totals on the session row from its payments and movements. */
    public function recalculate(CashierSession $session): CashierSession
    {
        // Every cash payment taken in the session went into the drawer, even if refunded later (refunds count separately).
        $sales = Payment::query()->withoutGlobalScopes()->where('cashier_session_id', $session->id)
            ->where('method', PaymentMethod::Cash->value)->get();
        $refunds = Payment::query()->withoutGlobalScopes()->where('refund_session_id', $session->id)
            ->where('method', PaymentMethod::Cash->value)->where('status', PaymentStatus::Refunded->value)->get();
        $movements = CashMovement::query()->withoutGlobalScopes()->where('cashier_session_id', $session->id)->get();

        $sum = fn ($rows, string $field) => $rows->reduce(fn (Money $c, $row) => $c->add($row->{$field}), Money::zero());

        $session->forceFill([
            'cash_sales' => $sum($sales, 'amount'),
            'cash_tips' => $sum($sales, 'tip'),
            'cash_refunds' => $sum($refunds, 'amount'),
            'pay_ins' => $sum($movements->where('type', CashMovementType::PayIn), 'amount'),
            'pay_outs' => $sum($movements->where('type', CashMovementType::PayOut), 'amount'),
        ]);
        if ($session->isDirty()) {
            $session->saveQuietly();
        }

        return $session;
    }

    /** @return array<int, array{method: string, count: int, total: int, tips: int}> every payment method taken in the session */
    public function totalsByMethod(CashierSession $session): array
    {
        return Payment::query()->withoutGlobalScopes()->where('cashier_session_id', $session->id)->get()
            ->groupBy(fn (Payment $p) => $p->method->value)
            ->map(fn ($payments, string $method) => [
                'method' => $method,
                'count' => $payments->count(),
                'total' => $payments->sum(fn (Payment $p) => $p->amount->amount),
                'tips' => $payments->sum(fn (Payment $p) => $p->tip->amount),
            ])->values()->all();
    }

    /** @param  array<int|string, int>  $breakdown */
    public static function countFromBreakdown(array $breakdown): Money
    {
        $total = 0;
        foreach ($breakdown as $value => $count) {
            $total += (int) $value * max(0, (int) $count);
        }

        return Money::of($total);
    }

    private function assertOpen(CashierSession $session): void
    {
        if (! $session->isOpen()) {
            throw new CashException(__('payments::payments.cash.errors.session_closed'));
        }
    }
}
