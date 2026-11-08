<?php

namespace App\Modules\Settings\Console;

use Illuminate\Console\Command;

/**
 * `php artisan rms:licence-keys --out=/safe/place/licence-signing.key` — Corral Systems office only.
 * Makes the key pair licences are signed with. The SECRET half goes to the file (keep it offline, backed up,
 * never in Git or on a restaurant's server); the PUBLIC half goes into config/licence.php.
 */
class LicenceKeysCommand extends Command
{
    protected $signature = 'rms:licence-keys {--out= : File to write the secret signing key to (must not exist yet)} {--id=corral-2026 : Name of the key}';

    protected $description = 'Corral Systems only: make a licence signing key pair';

    public function handle(): int
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            $this->components->error('Say where to keep the secret key, e.g. --out=/Users/you/CorralSystems/licence-signing.key');

            return self::FAILURE;
        }
        if (file_exists($out)) {
            $this->components->error("{$out} already exists. A signing key is never overwritten: every licence issued with it would stop working.");

            return self::FAILURE;
        }
        if (! is_dir(dirname($out)) && ! mkdir(dirname($out), 0700, true)) {
            $this->components->error('Could not create the folder for the key.');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $id = (string) $this->option('id');
        file_put_contents($out, json_encode(['id' => $id, 'secret' => base64_encode(sodium_crypto_sign_secretkey($pair))], JSON_PRETTY_PRINT)."\n");
        chmod($out, 0600);

        $this->components->info("Secret signing key written to {$out}. Keep it offline and backed up; never share it.");
        $this->line("Public key for config/licence.php ('public_keys'):");
        $this->line("    '{$id}' => '".base64_encode(sodium_crypto_sign_publickey($pair))."',");

        return self::SUCCESS;
    }
}
