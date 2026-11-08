<?php

namespace App\Modules\Settings\Console;

use App\Support\Audit\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan rms:audit:check` — nightly: walk the tamper-evident audit chain (E9.4). The result shows on
 * Settings → History check; a problem is also written to the server log. `--code=` tests a code printed
 * on an old Z report.
 */
class AuditCheckCommand extends Command
{
    protected $signature = 'rms:audit:check {--code= : A history check code printed on a Z report}';

    protected $description = 'Check that no audit log entry was changed, removed or slipped in';

    public function handle(AuditChain $chain): int
    {
        if ($code = $this->option('code')) {
            $match = $chain->codeMatches((string) $code);
            if ($match === null) {
                $this->components->error(__('settings::settings.history.code_unreadable'));

                return self::INVALID;
            }
            $match ? $this->components->info(__('settings::settings.history.code_ok', ['code' => $code]))
                : $this->components->error(__('settings::settings.history.code_bad', ['code' => $code]));

            return $match ? self::SUCCESS : self::FAILURE;
        }

        $chain->seal();
        $result = $chain->check();
        if ($result['problems'] === []) {
            $this->components->info(__('settings::settings.history.all_good', ['count' => $result['checked']]).' '.__('settings::settings.history.current_code', ['code' => $result['code']]));

            return self::SUCCESS;
        }
        Log::warning('Audit log check found problems', ['problems' => $result['problems']]);
        $this->components->error(trans_choice('settings::settings.history.problems_found', count($result['problems']), ['count' => count($result['problems'])]));
        foreach ($result['problems'] as $problem) {
            $this->line('  '.AuditChain::describe($problem));
        }

        return self::FAILURE;
    }
}
