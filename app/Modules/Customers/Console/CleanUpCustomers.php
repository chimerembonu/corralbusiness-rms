<?php

namespace App\Modules\Customers\Console;

use App\Modules\Customers\Services\CustomerPrivacyService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Models\Tenant;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;

/**
 * `php artisan rms:customers:clean-up` — nightly (Complete Edition E9.3): for each restaurant that set a
 * retention period (Till settings → Customers), remove the personal details of customers not seen for that long.
 */
class CleanUpCustomers extends Command
{
    protected $signature = 'rms:customers:clean-up';

    protected $description = 'Remove customers\' personal details after the restaurant\'s retention period';

    public function handle(CustomerPrivacyService $privacy): int
    {
        foreach (Tenant::query()->get() as $tenant) {
            $result = Tenancy::runAs($tenant, null, function () use ($privacy) {
                $months = app(TillSettings::class)->customerRetentionMonths();

                return $months > 0 ? $privacy->cleanUp($months) : null;
            });
            if ($result) {
                $this->components->info("{$tenant->name}: {$result['customers']} customer(s) and {$result['orders']} order(s) cleaned up.");
            }
        }

        return self::SUCCESS;
    }
}
