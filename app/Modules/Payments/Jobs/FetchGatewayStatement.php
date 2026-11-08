<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Gateways\GatewayReconciliationService;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Models\Tenant;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Fetches a provider's list of payments for the payment matching report (E2.5), in the background. */
class FetchGatewayStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // a failure is shown on the report; the manager can press the button again

    public int $timeout = 120;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $branchId,
        public readonly string $gateway,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function handle(GatewayReconciliationService $matching): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if (! $tenant) {
            return;
        }
        $branch = $this->branchId ? Branch::query()->withoutGlobalScopes()->find($this->branchId) : null;
        Tenancy::runAs($tenant, $branch, fn () => $matching->fetchStatement($this->gateway, $this->from, $this->to));
    }
}
