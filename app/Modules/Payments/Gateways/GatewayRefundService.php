<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Jobs\SendGatewayRefund;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Payments\Models\GatewayTransaction;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refunds of payments taken through a provider (Complete Edition E2.4).
 *
 * When a manager refunds such a payment at the till (PaymentService::refund, manager PIN), the money goes
 * back through the same provider if it can do that, in the background so the till never waits for the
 * internet; the provider is asked how it is going until it is finished. If the provider cannot send money
 * back, or refuses, the till shows "refund by hand" and the refund stays on the managers' list until a
 * manager confirms the customer got the money.
 */
class GatewayRefundService
{
    public function __construct(private readonly GatewayManager $gateways) {}

    /** Called when a payment is refunded. Nothing happens for payments that did not come through a provider. */
    public function startFor(Payment $payment): ?GatewayRefund
    {
        $transaction = GatewayTransaction::query()->withoutGlobalScopes()->where('payment_id', $payment->id)->first();
        if (! $transaction || GatewayRefund::query()->withoutGlobalScopes()->where('payment_id', $payment->id)->exists()) {
            return null;
        }

        $canSend = $this->canSend($transaction->gateway);
        $refund = new GatewayRefund([
            'branch_id' => $payment->branch_id,
            'gateway_transaction_id' => $transaction->id,
            'payment_id' => $payment->id,
            'gateway' => $transaction->gateway,
            'reference' => 'RF'.Str::upper((string) Str::ulid()),
            'amount' => $transaction->amount->add($transaction->tip), // what the customer paid through the provider
            'status' => $canSend ? RefundStatus::Queued : RefundStatus::ByHand,
            'message' => $canSend ? __('payments::payments.refunds.queued') : __('payments::payments.refunds.cannot', ['provider' => $this->name($transaction->gateway)]),
            'reason' => mb_substr((string) $payment->refund_reason, 0, 255),
            'approved_by_id' => $payment->refunded_by_id,
        ]);
        $refund->tenant_id = $payment->tenant_id;
        $refund->save();
        activity('payments')->performedOn($refund)->causedBy($payment->refundedBy)
            ->withProperties(['payment' => $payment->id, 'gateway' => $refund->gateway, 'amount' => $refund->amount->amount, 'status' => $refund->status->value])
            ->log('gateway_refund_started');

        if ($canSend) {
            SendGatewayRefund::dispatch($refund->id)->afterCommit();
        }

        return $refund;
    }

    /**
     * Move one refund along: send it if not sent yet, otherwise ask how it is going. Safe to call as often as
     * needed and from several places at once (job, background check, till).
     */
    public function advance(GatewayRefund $refund): GatewayRefund
    {
        $lock = Cache::lock('rms:refund:'.$refund->id, 30);
        if (! $lock->get()) {
            return $refund;
        }
        try {
            $refund->refresh();
            if (! in_array($refund->status, [RefundStatus::Queued, RefundStatus::Sent], true)) {
                return $refund;
            }
            if (! $this->canSend($refund->gateway)) {
                return $this->save($refund, RefundStatus::Failed, __('payments::payments.refunds.not_ready', ['provider' => $this->name($refund->gateway)]));
            }
            $gateway = $this->gateways->make($refund->gateway);
            $transaction = $refund->transaction()->withoutGlobalScopes()->firstOrFail();

            try {
                $response = $refund->status === RefundStatus::Queued
                    ? $gateway->refund($transaction->reference, $transaction->provider_reference, $refund->amount, $refund->reference)
                    : $gateway->refundStatus($refund->reference, $refund->provider_reference);
            } catch (Throwable $e) {
                report($e);

                return $this->unreachable($refund);
            }

            return $this->apply($refund, $response);
        } finally {
            $lock->release();
        }
    }

    /** A manager confirms the customer got the money back another way (cash, or MoMo sent from the restaurant's phone). */
    public function markDoneByHand(GatewayRefund $refund, User $manager, ?string $note = null): GatewayRefund
    {
        if (! $manager->can(Permission::OrdersRefund->value)) {
            throw new PaymentException(__('payments::payments.refunds.errors.manager'));
        }

        return DB::transaction(function () use ($refund, $manager, $note) {
            $locked = GatewayRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->needsHand()) {
                throw new PaymentException(__('payments::payments.refunds.errors.not_by_hand'));
            }
            $locked->forceFill([
                'status' => RefundStatus::DoneByHand,
                'done_by_hand_by_id' => $manager->id,
                'done_by_hand_note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
                'message' => __('payments::payments.refunds.done_by_hand_message', ['name' => $manager->name]),
                'completed_at' => now(),
                'next_check_at' => null,
            ])->save();
            activity('payments')->performedOn($locked)->causedBy($manager)->withProperties(['note' => $locked->done_by_hand_note])->log('gateway_refund_done_by_hand');

            return $locked;
        });
    }

    /** Background job (every minute): move along every refund that is due, for every restaurant on this server. */
    public function checkOutstanding(): int
    {
        $count = 0;
        GatewayRefund::query()->withoutGlobalScopes()->due()->orderBy('created_at')->limit(100)->get()
            ->each(function (GatewayRefund $refund) use (&$count) {
                $branch = Branch::query()->withoutGlobalScopes()->with('tenant')->find($refund->branch_id);
                if (! $branch) {
                    return;
                }
                Tenancy::runAs($branch->tenant, $branch, fn () => rescue(fn () => $this->advance($refund), report: true));
                $count++;
            });

        return $count;
    }

    private function apply(GatewayRefund $refund, GatewayResponse $response): GatewayRefund
    {
        $refund->provider_reference = $response->providerReference ?? $refund->provider_reference;

        return match ($response->status) {
            GatewayStatus::Succeeded => $this->save($refund, RefundStatus::Refunded, $response->message ?? __('payments::payments.refunds.refunded')),
            GatewayStatus::Failed => $this->save($refund, RefundStatus::Failed, __('payments::payments.refunds.refused', ['provider' => $this->name($refund->gateway), 'reason' => $response->message ?? '—'])),
            GatewayStatus::NeedsAttention => $this->save($refund, RefundStatus::Failed, $response->message ?? __('payments::payments.refunds.refused', ['provider' => $this->name($refund->gateway), 'reason' => '—'])),
            // The provider has no such refund: it never arrived, so send it again (our reference stops a double refund).
            GatewayStatus::Expired => $this->save($refund, RefundStatus::Queued, __('payments::payments.refunds.queued')),
            default => $this->save($refund, RefundStatus::Sent, $response->message ?? __('payments::payments.refunds.sent', ['provider' => $this->name($refund->gateway)])),
        };
    }

    /** Could not reach the provider: try again later; after a few days of no internet, a person refunds by hand. */
    private function unreachable(GatewayRefund $refund): GatewayRefund
    {
        $refund->attempts++;
        if ($refund->status === RefundStatus::Queued && $refund->created_at->lt(now()->subDays((int) config('payments.refunds.give_up_sending_after_days', 3)))) {
            return $this->save($refund, RefundStatus::Failed, __('payments::payments.refunds.gave_up', ['provider' => $this->name($refund->gateway)]));
        }

        return $this->save($refund, $refund->status, __('payments::payments.refunds.unreachable', ['provider' => $this->name($refund->gateway)]));
    }

    private function save(GatewayRefund $refund, RefundStatus $status, string $message): GatewayRefund
    {
        $wasSent = $refund->status === RefundStatus::Sent;
        $refund->status = $status;
        $refund->message = mb_substr($message, 0, 255);
        if ($status === RefundStatus::Sent && $wasSent) {
            $refund->checks++;
        }
        $refund->next_check_at = match ($status) {
            RefundStatus::Queued => now()->addMinute(),
            // Refunds take hours or days: ask often at first, then less often, and stop after a month.
            RefundStatus::Sent => $refund->created_at->lt(now()->subDays(30)) ? null
                : now()->addMinutes($refund->checks < 10 ? 1 : ($refund->checks < 40 ? 15 : 60)),
            default => null,
        };
        if ($status->isFinal() || $status->needsHand()) {
            $refund->completed_at ??= now();
        }
        $refund->save();
        if ($refund->wasChanged('status')) {
            activity('payments')->performedOn($refund)->withProperties(['status' => $status->value, 'message' => $refund->message])->log('gateway_refund_'.$status->value);
        }

        return $refund;
    }

    private function canSend(string $gateway): bool
    {
        try {
            return $this->gateways->isReady($gateway) && $this->gateways->make($gateway)->supports(GatewayCapability::Refund);
        } catch (Throwable) {
            return false;
        }
    }

    private function name(string $gateway): string
    {
        return __('payments::payments.gateway.names.'.$gateway);
    }
}
