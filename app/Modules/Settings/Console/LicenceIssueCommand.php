<?php

namespace App\Modules\Settings\Console;

use App\Support\Licence\LicenceCodec;
use App\Support\Licence\LicenceException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * `php artisan rms:licence-issue --restaurant="Mama's Kitchen" --plan=pro --branches=1 --expires=2027-11-30 --tenant=<Restaurant ID>`
 * Corral Systems office only: makes a licence code with the secret signing key (rms:licence-keys).
 * --tenant ties the code to one restaurant's ID (shown on its Licence page) so it cannot be reused elsewhere.
 */
class LicenceIssueCommand extends Command
{
    protected $signature = 'rms:licence-issue
        {--restaurant= : Restaurant name, as it should appear on the Licence page}
        {--plan= : starter, pro or business}
        {--branches=1 : How many branches it covers}
        {--expires= : Last day it is valid, YYYY-MM-DD}
        {--tenant= : Restaurant ID from its Licence page (required, so the code works for that restaurant only)}
        {--any-restaurant : Only for a demo or trial code: let it work at any restaurant}
        {--extras= : Features sold on top of the plan, comma separated (e.g. payroll,whatsapp)}
        {--key= : Secret signing key file (default: RMS_LICENCE_SIGNING_KEY)}';

    protected $description = 'Corral Systems only: issue a licence code for a restaurant';

    public function handle(): int
    {
        $keyFile = (string) ($this->option('key') ?: config('licence.signing_key_path'));
        $key = is_file($keyFile) ? json_decode((string) file_get_contents($keyFile), true) : null;
        if (! is_array($key) || ! isset($key['id'], $key['secret'])) {
            $this->components->error('No signing key found. Pass --key=/path/to/licence-signing.key.');

            return self::FAILURE;
        }

        $plan = (string) $this->option('plan');
        if (! array_key_exists($plan, config('features.tiers', []))) {
            $this->components->error('--plan must be one of: '.implode(', ', array_keys(config('features.tiers', []))));

            return self::FAILURE;
        }
        try {
            $expires = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('expires'));
        } catch (Throwable) {
            $expires = false;
        }
        $restaurant = trim((string) $this->option('restaurant'));
        $branches = filter_var($this->option('branches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $expires || $restaurant === '' || $branches === false) {
            $this->components->error('Give --restaurant, --expires=YYYY-MM-DD and --branches (1 or more).');

            return self::FAILURE;
        }
        if (blank($this->option('tenant')) && ! $this->option('any-restaurant')) {
            $this->components->error('Give --tenant (the Restaurant ID on its Licence page), so the code cannot be used at other restaurants. For a demo code only: --any-restaurant.');

            return self::FAILURE;
        }
        $extras = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('extras')))));
        if ($unknown = array_diff($extras, array_keys(config('features.features', [])))) {
            $this->components->error('Unknown feature(s): '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $details = array_filter([
            'id' => 'L-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'restaurant' => $restaurant,
            'tenant' => $this->option('tenant') ?: null,
            'plan' => $plan,
            'branches' => $branches,
            'issued' => now()->toDateString(),
            'expires' => $expires->toDateString(),
            'extras' => $extras ?: null,
        ], fn ($v) => $v !== null);

        try {
            $code = LicenceCodec::issue($details, $key['secret'], $key['id']);
        } catch (LicenceException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Licence {$details['id']}: {$restaurant}, {$plan}, {$branches} branch(es), valid until {$details['expires']}.");
        $this->line($code);

        return self::SUCCESS;
    }
}
