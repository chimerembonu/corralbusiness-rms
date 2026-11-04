<?php

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Gateways\GatewayPaymentService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/** `php artisan rms:payments:check` — every minute: ask providers about prompts not yet settled, in case money arrived late. */
class CheckGatewayPayments extends Command
{
    protected $signature = 'rms:payments:check';

    protected $description = 'Check payment-provider prompts that are not settled yet and record any that went through';

    public function handle(GatewayPaymentService $service): int
    {
        Tenancy::forget(); // the job runs for every restaurant on this server; each transaction carries its own
        $count = $service->checkOutstanding();
        $this->components->info("Checked {$count} payment(s).");

        return self::SUCCESS;
    }
}
