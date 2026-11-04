<?php

/* Compliance assessment: an update must never run without a fresh backup. */
it('stops the update when the backup before it fails', function () {
    $script = file_get_contents(base_path('scripts/update.sh'));

    expect($script)->toContain('set -euo pipefail')
        ->and($script)->toMatch('/if \[ "\$\{SKIP_BACKUP:-0\}" = "1" \]/')
        ->and($script)->toMatch('/elif ! docker compose exec -T app php artisan rms:backup/')
        ->and($script)->toContain('exit 1')
        ->and($script)->not->toMatch('/rms:backup[^\n]*\|\|\s*echo/')
        // the backup comes before any code is pulled or rebuilt
        ->and(strpos($script, 'rms:backup'))->toBeLessThan(strpos($script, 'git pull'));
});
