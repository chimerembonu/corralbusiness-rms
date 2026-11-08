<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Models\Tenant;
use App\Support\Licence\LicenceException;
use App\Support\Licence\LicenceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * `php artisan rms:licence` shows the restaurant's licence; `php artisan rms:licence --install=CBL1....`
 * installs a new code (the same as pasting it on Back office → Settings → Licence).
 */
class LicenceCommand extends Command
{
    protected $signature = 'rms:licence {--install= : A licence code from Corral Systems} {--tenant= : Restaurant slug, when the server has more than one}';

    protected $description = 'Show or install the restaurant\'s licence';

    public function handle(TenantContext $context): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::query()->where('slug', $this->option('tenant'))->first()
            : (Tenant::query()->count() === 1 ? Tenant::query()->first() : null);
        if (! $tenant) {
            $this->components->error('Say which restaurant with --tenant=<slug>.');

            return self::FAILURE;
        }

        return $context->runAs($tenant, null, function () use ($tenant) {
            $service = app(LicenceService::class);
            if ($code = $this->option('install')) {
                try {
                    $service->install($code);
                } catch (LicenceException $e) {
                    $this->components->error($e->getMessage());

                    return self::FAILURE;
                }
                $this->components->info('Licence installed.');
            }

            $licence = $service->current();
            $this->components->twoColumnDetail('Restaurant ID', $tenant->id);
            if (! $licence) {
                $this->components->twoColumnDetail('Licence', 'none (extra features stay off)');

                return self::SUCCESS;
            }
            $this->components->twoColumnDetail('Licence', $licence->id.' · '.$licence->restaurant);
            $this->components->twoColumnDetail('Plan', $licence->plan.($licence->extras ? ' + '.implode(', ', $licence->extras) : ''));
            $this->components->twoColumnDetail('Branches', $service->branchesInUse().' of '.$licence->branches);
            $this->components->twoColumnDetail('Valid until', $licence->expires->toDateString());
            $this->components->twoColumnDetail('Status', $service->status()->value);

            return self::SUCCESS;
        });
    }
}
