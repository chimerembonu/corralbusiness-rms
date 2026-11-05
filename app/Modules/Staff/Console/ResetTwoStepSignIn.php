<?php

namespace App\Modules\Staff\Console;

use App\Modules\Staff\Models\User;
use App\Support\Tenancy\TenantScope;
use Illuminate\Console\Command;

/**
 * `php artisan rms:two-step:reset owner@example.com` — for someone who lost both their phone and their
 * recovery codes (E9.2). Run on the store server only: switches their two-step sign-in off, so they can sign
 * in with their password and set it up again. Recorded in the activity log.
 */
class ResetTwoStepSignIn extends Command
{
    protected $signature = 'rms:two-step:reset {email : The back-office login email}';

    protected $description = 'Switch off two-step sign-in for one person who lost their phone and recovery codes';

    public function handle(): int
    {
        $user = User::query()->withoutGlobalScope(TenantScope::class)->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        if (! $user) {
            $this->components->error('No back-office login with that email.');

            return self::FAILURE;
        }
        if (! $user->hasTwoStepSignIn()) {
            $this->components->info("{$user->name} has no two-step sign-in set up.");

            return self::SUCCESS;
        }
        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        activity('security')->performedOn($user)->withProperties(['by' => 'server command'])->log('two_step_sign_in_reset');
        $this->components->info("Two-step sign-in switched off for {$user->name}. They sign in with their password and set it up again on their profile page.");

        return self::SUCCESS;
    }
}
