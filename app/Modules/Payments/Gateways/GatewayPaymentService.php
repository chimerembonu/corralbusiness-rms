<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Models\GatewayTransaction;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Models\User;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Taking money through a payment provider (Complete Edition E2), whichever provider it is.
 *
 * The store server cannot receive calls from the internet, so it never waits for the provider to call back:
 * the till asks "did it go through?" every few seconds, and a background job keeps asking for half an hour
 * in case the customer approves late. When the money is confirmed the payment is recorded on the order
 * exactly once, through the normal PaymentService, so cash-ups, reports and receipts work as for any payment.
 */
class GatewayPaymentService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly FeatureService $features,
    ) {}

    /** Send a payment request to the customer's phone. Returns the transaction to watch. */
    public function startMomoPrompt(Order $order, MomoProvider $network, string $phone, Money $amount, User $by, ?Money $tip = null): GatewayTransaction
    {
        if (! $this->features->isOn('momo_prompt')) {
            throw new PaymentException(__('payments::payments.gateway.errors.feature_off'));
        }
        $tip ??= Money::zero();
        if (! $amount->isPositive() || $tip->isNegative() || $amount->greaterThan($order->balanceDue())) {
            throw new PaymentException(__('payments::payments.errors.over_balance'));
        }
        $local = self::normalisePhone($phone) ?? throw new PaymentException(__('payments::payments.gateway.errors.phone'));

        $gateway = $this->gateways->forPurpose('till_momo', (bool) $order->is_training)
            ?? throw new PaymentException(__($order->is_training ? 'payments::payments.gateway.errors.no_demo' : 'payments::payments.gateway.errors.none_ready'));
        if (! in_array($network, $gateway->networks(), true)) {
            throw new PaymentException(__('payments::payments.gateway.errors.network', ['network' => $network->label(), 'provider' => __('payments::payments.gateway.names.'.$gateway->key())]));
        }

        $transaction = DB::transaction(function () use ($order, $gateway, $network, $local, $amount, $tip, $by) {
            // One prompt at a time per order: a double tap must not send two requests to the customer.
            Order::query()->whereKey($order->id)->lockForUpdate()->first();
            $waiting = GatewayTransaction::query()->where('order_id', $order->id)
                ->whereIn('status', [GatewayStatus::Pending->value, GatewayStatus::NeedsOtp->value])->where('expires_at', '>', now())->exists();
            if ($waiting) {
                throw new PaymentException(__('payments::payments.gateway.errors.already_waiting'));
            }

            return GatewayTransaction::create([
                'branch_id' => $order->branch_id,
                'gateway' => $gateway->key(),
                'purpose' => 'till_momo',
                'order_id' => $order->id,
                'reference' => $gateway->newReference(),
                'amount' => $amount,
                'tip' => $tip,
                'network' => $network,
                'phone_masked' => GatewayTransaction::maskPhone($local),
                'is_training' => (bool) $order->is_training,
                'requested_by_id' => $by->id,
                'expires_at' => now()->addMinutes((int) config('payments.momo.expire_after_minutes', 3)),
            ]);
        });

        $response = $this->call(sending: true, request: fn () => $gateway->requestMomo(new ChargeRequest(
            reference: $transaction->reference,
            amount: $amount->add($tip),
            description: __('payments::payments.gateway.description', ['number' => $order->displayNumber(), 'branch' => $order->branch?->name ?? '']),
            network: $network,
            phone: $local,
        )));

        return $this->apply($transaction, $response);
    }

    /** Some networks (e.g. Telecel) answer with a voucher code the customer reads from their phone. */
    public function submitOtp(GatewayTransaction $transaction, string $otp): GatewayTransaction
    {
        if ($transaction->status !== GatewayStatus::NeedsOtp) {
            return $transaction;
        }
        $gateway = $this->gateways->make($transaction->gateway);

        return $this->apply($transaction, $this->call(sending: true, request: fn () => $gateway->submitOtp($transaction->reference, $transaction->provider_reference, trim($otp))));
    }

    /** Ask the provider whether the payment went through, and record it if it did. Safe to call as often as needed. */
    public function refresh(GatewayTransaction $transaction): GatewayTransaction
    {
        $transaction->refresh();
        if ($transaction->payment_id !== null || in_array($transaction->status, [GatewayStatus::Succeeded, GatewayStatus::Failed, GatewayStatus::NeedsAttention], true)) {
            return $transaction;
        }
        $gateway = $this->gateways->make($transaction->gateway);
        if (! $gateway->supports(GatewayCapability::StatusCheck)) {
            return $transaction;
        }

        $response = $this->call(sending: false, request: fn () => $gateway->status($transaction->reference, $transaction->provider_reference));
        $transaction->forceFill(['checks' => $transaction->checks + 1, 'last_checked_at' => now()])->save();

        // Still waiting past the time limit: tell the till it timed out, but keep checking in the background.
        if ($response->status === GatewayStatus::Pending && $transaction->expires_at?->isPast()) {
            $response = new GatewayResponse(GatewayStatus::Expired, $response->providerReference, __('payments::payments.gateway.expired'));
        }
        if ($transaction->status === GatewayStatus::Cancelled && $response->status === GatewayStatus::Pending) {
            return $transaction; // the cashier gave up; nothing new yet
        }

        return $this->apply($transaction, $response);
    }

    /** The cashier stops waiting (customer will pay another way). We still check later, in case the money arrives. */
    public function cancel(GatewayTransaction $transaction, User $by): GatewayTransaction
    {
        if (in_array($transaction->status, [GatewayStatus::Pending, GatewayStatus::NeedsOtp], true)) {
            $transaction->forceFill(['status' => GatewayStatus::Cancelled, 'message' => __('payments::payments.gateway.cancelled')])->save();
            activity('payments')->performedOn($transaction)->causedBy($by)->log('gateway_prompt_cancelled');
        }

        return $transaction;
    }

    /** Background job: re-check every recent prompt that has not been settled. */
    public function checkOutstanding(): int
    {
        $count = 0;
        GatewayTransaction::query()->withoutGlobalScopes()->stillChecking()->orderBy('created_at')->limit(200)->get()
            ->each(function (GatewayTransaction $transaction) use (&$count) {
                // Work as that restaurant and branch: its provider keys, its till, its order.
                $branch = Branch::query()->withoutGlobalScopes()->with('tenant')->find($transaction->branch_id);
                if (! $branch) {
                    return;
                }
                Tenancy::set($branch->tenant, $branch);
                try {
                    rescue(fn () => $this->refresh($transaction), report: true);
                    $count++;
                } finally {
                    Tenancy::forget();
                }
            });

        return $count;
    }

    public static function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 3);
        }

        return preg_match('/^0[2-5]\d{8}$/', $digits) ? $digits : null;
    }

    private function apply(GatewayTransaction $transaction, GatewayResponse $response): GatewayTransaction
    {
        // If the provider tells us what was actually paid and it is not what we asked for, a manager decides.
        $expected = $transaction->amount->add($transaction->tip)->amount;
        if ($response->status === GatewayStatus::Succeeded && isset($response->details['amount']) && (int) $response->details['amount'] !== $expected) {
            $response = new GatewayResponse(GatewayStatus::NeedsAttention, $response->providerReference, __('payments::payments.gateway.amount_mismatch', [
                'paid' => money(Money::of((int) $response->details['amount'])), 'asked' => money(Money::of($expected)),
            ]));
        }

        $transaction->forceFill([
            'status' => $response->status === GatewayStatus::Succeeded ? $transaction->status : $response->status,
            'provider_reference' => $response->providerReference ?? $transaction->provider_reference,
            'message' => $response->message ? mb_substr($response->message, 0, 255) : $transaction->message,
        ])->save();

        if ($response->status === GatewayStatus::Succeeded) {
            $this->complete($transaction);
        }

        return $transaction->refresh();
    }

    /** Money confirmed: record it on the order once. If that is no longer possible, flag it for the manager — never lose it. */
    private function complete(GatewayTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $locked = GatewayTransaction::query()->whereKey($transaction->id)->lockForUpdate()->first();
            if ($locked->payment_id !== null) {
                return;
            }
            try {
                $payment = $this->payments->record(
                    $locked->order()->firstOrFail(),
                    PaymentMethod::MobileMoney,
                    $locked->amount,
                    $locked->requestedBy,
                    reference: $locked->provider_reference ?? $locked->reference,
                    notes: __('payments::payments.gateway.payment_note', ['provider' => __('payments::payments.gateway.names.'.$locked->gateway)]),
                    provider: $locked->network,
                    tip: $locked->tip,
                );
                $locked->forceFill(['payment_id' => $payment->id, 'status' => GatewayStatus::Succeeded, 'completed_at' => now()])->save();
            } catch (RuntimeException $e) {
                // e.g. the order was paid in cash meanwhile, or the till was closed: the customer's money is real.
                $locked->forceFill(['status' => GatewayStatus::NeedsAttention, 'message' => mb_substr(__('payments::payments.gateway.needs_attention').' '.$e->getMessage(), 0, 255), 'completed_at' => now()])->save();
                activity('payments')->performedOn($locked)->withProperties(['reason' => $e->getMessage()])->log('gateway_needs_attention');
            }
        });
    }

    /**
     * A provider that is down or slow becomes a message, never a crashed till. If a request could not be
     * *sent*, it failed (try again); if a *status check* could not get through, we simply keep waiting.
     */
    private function call(bool $sending, callable $request): GatewayResponse
    {
        try {
            return $request();
        } catch (Contracts\UnsupportedByGateway $e) {
            throw new PaymentException($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $sending
                ? GatewayResponse::failed(__('payments::payments.gateway.unreachable_send'))
                : GatewayResponse::pending(null, __('payments::payments.gateway.unreachable'));
        }
    }
}
