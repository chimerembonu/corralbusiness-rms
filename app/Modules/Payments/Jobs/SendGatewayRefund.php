<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Gateways\GatewayRefundService;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Settings\Models\Branch;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one refund to its provider straight after the manager approves it (the every-minute check is the safety net). */
class SendGatewayRefund implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // GatewayRefundService schedules its own retries; the background check picks them up

    public function __construct(public readonly string $refundId) {}

    public function handle(GatewayRefundService $refunds): void
    {
        $refund = GatewayRefund::query()->withoutGlobalScopes()->find($this->refundId);
        $branch = $refund ? Branch::query()->withoutGlobalScopes()->with('tenant')->find($refund->branch_id) : null;
        if (! $refund || ! $branch) {
            return;
        }
        Tenancy::set($branch->tenant, $branch);
        try {
            $refunds->advance($refund);
        } finally {
            Tenancy::forget();
        }
    }
}
