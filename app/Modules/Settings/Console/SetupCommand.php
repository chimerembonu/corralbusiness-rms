<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Services\SetupService;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Errors\UserFacingException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * `php artisan rms:setup` — first-run setup on a real store server: the restaurant, its branch
 * and the owner login. No demo data. Asks questions when options are not given.
 */
class SetupCommand extends Command
{
    protected $signature = 'rms:setup
        {--check : Exit 0 when a restaurant already exists, 1 when not (used by install.sh)}
        {--restaurant= : Restaurant name}
        {--branch= : Branch name}
        {--owner-name= : Owner full name}
        {--email= : Owner email (back-office login)}
        {--password= : Owner password (min 8)}
        {--pin= : Owner till PIN (4–6 digits)}';

    protected $description = 'Set up the restaurant, branch and owner login on a new store server';

    public function handle(SetupService $setup): int
    {
        if ($this->option('check')) {
            return $setup->isOpen() ? self::FAILURE : self::SUCCESS;
        }

        if (! $setup->isOpen()) {
            (new RolesAndPermissionsSeeder)->run();
            $this->components->warn('A restaurant is already set up on this server. Nothing changed.');
            $this->components->info('To add more branches or restaurants use Back office → Settings, or rms:demo on a test machine.');

            return self::SUCCESS;
        }

        $restaurant = $this->option('restaurant') ?: text('Restaurant name', 'e.g. Mama Efua Chop Bar', required: true);
        $branchName = $this->option('branch') ?: text('Branch name', 'e.g. Osu Branch', default: 'Main Branch', required: true);
        $ownerName = $this->option('owner-name') ?: text('Owner full name', required: true);
        $email = $this->option('email') ?: text('Owner email (used to log in to the back office)', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email');
        $password = $this->option('password') ?: password('Owner password (at least 8 characters)', required: true, validate: fn ($v) => strlen($v) >= 8 ? null : 'At least 8 characters');
        $pin = $this->option('pin') ?: text('Owner till PIN (4–6 digits)', required: true, validate: fn ($v) => preg_match('/^\d{4,6}$/', $v) ? null : 'Digits only, 4 to 6');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $this->components->error('Check the email and password (at least 8 characters).');

            return self::FAILURE;
        }
        try {
            PinHasher::validate($pin);
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        try {
            $setup->complete([
                'restaurant' => $restaurant, 'branch' => $branchName, 'owner_name' => $ownerName,
                'email' => $email, 'password' => $password, 'pin' => $pin,
            ]);
        } catch (UserFacingException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Restaurant \"{$restaurant}\" with branch \"{$branchName}\" is ready.");
        $this->components->twoColumnDetail('Back office login', $email);
        $this->components->twoColumnDetail('Till PIN', $pin);
        $this->components->info('Next: Back office → Settings (taxes, receipt design, tills, printers), then Menu. (The browser setup at /setup does all of this in one go.)');

        return self::SUCCESS;
    }
}
