<?php

use App\Modules\Settings\Services\BackupService;

/* Re-review: a restored backup must not be able to plant an SVG (which can carry a script) under the app's own address. */
it('refuses SVG and BMP files inside a backup and still takes the picture types uploads accept', function () {
    $backups = app(BackupService::class);
    expect($backups->isSafeEntry('uploads/menu/items/logo.svg'))->toBeFalse()
        ->and($backups->isSafeEntry('uploads/receipts/logo.SVG'))->toBeFalse()
        ->and($backups->isSafeEntry('uploads/x.bmp'))->toBeFalse()
        ->and($backups->isSafeEntry('uploads/menu/items/jollof.png'))->toBeTrue()
        ->and($backups->isSafeEntry('uploads/receipts/logo.jpg'))->toBeTrue()
        ->and($backups->isSafeEntry('uploads/receipts/logo.webp'))->toBeTrue();
});
