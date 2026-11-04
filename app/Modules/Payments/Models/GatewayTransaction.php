<?php

namespace App\Modules\Payments\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayTransaction extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'gateway', 'purpose', 'order_id', 'payment_id', 'reference', 'provider_reference', 'amount', 'tip', 'currency',
        'network', 'phone_masked', 'status', 'message', 'is_training', 'requested_by_id', 'checks', 'last_checked_at', 'expires_at', 'completed_at'];

    protected $attributes = ['status' => 'pending', 'tip' => 0, 'checks' => 0, 'currency' => 'GHS', 'is_training' => false];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'tip' => MoneyCast::class,
            'network' => MomoProvider::class,
            'status' => GatewayStatus::class,
            'is_training' => 'boolean',
            'last_checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /** Still worth asking the provider about: waiting, or given up / timed out but recent enough that money may still arrive. */
    public function scopeStillChecking(Builder $q): Builder
    {
        return $q->whereIn('status', [GatewayStatus::Pending->value, GatewayStatus::NeedsOtp->value, GatewayStatus::Cancelled->value, GatewayStatus::Expired->value])
            ->whereNull('payment_id')
            ->where('created_at', '>=', now()->subMinutes((int) config('payments.momo.keep_checking_minutes', 30)));
    }

    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits === '' ? null : substr($digits, 0, 3).'•••'.substr($digits, -4);
    }
}
