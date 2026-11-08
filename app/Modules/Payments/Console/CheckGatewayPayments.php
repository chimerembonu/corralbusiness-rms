<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Gateways\GatewayPaymentService;
use App\Modules\Payments\Gateways\GatewayRefundService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan rms:payments:check` — every minute: ask providers about prompts not yet settled, in case money
 * arrived late, and move refunds through providers along (send, then follow until finished).
 */
class CheckGatewayPayments extends Command
{
    protected $signature = 'rms:payments:check';

    protected $description = 'Check payment-provider prompts and refunds that are not settled yet';

    public function handle(GatewayPaymentService $service, GatewayRefundService $refunds): int
    {
        Tenancy::forget(); // the job runs for every restaurant on this server; each transaction carries its own
        $count = $service->checkOutstanding();
        $refunded = $refunds->checkOutstanding();
        $this->components->info("Checked {$count} payment(s) and {$refunded} refund(s).");

        return self::SUCCESS;
    }
}
