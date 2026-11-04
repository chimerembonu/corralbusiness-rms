<?php

use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Services\BackupService;
use Illuminate\Support\Facades\File;

/*
 * Compliance assessment (Data Protection Act 843): a backup holds every customer, staff member and sale,
 * and can be copied to a USB stick, so every file inside it is locked with AES-256.
 */
beforeEach(function () {
    provisionTenant();
    $this->menu = demoMenu();
    $this->backups = app(BackupService::class);
    File::ensureDirectoryExists(storage_path('backups'));
    File::cleanDirectory(storage_path('backups'));
});

// Empty the folder but keep it: other tests write straight into storage/backups.
afterEach(fn () => File::cleanDirectory(storage_path('backups')));

it('locks every file in a backup so it cannot be read without the password', function () {
    $zip = $this->backups->create('locked');
    $archive = new ZipArchive;
    $archive->open($zip);

    for ($i = 0; $i < $archive->numFiles; $i++) {
        expect($archive->statIndex($i)['encryption_method'])->toBe(ZipArchive::EM_AES_256);
    }
    expect(@$archive->getFromName('database.sql'))->toBeFalse(); // no password: unreadable

    $archive->setPassword('wrong password');
    expect(@$archive->getFromName('database.sql'))->toBeFalse();

    $archive->setPassword($this->backups->password());
    expect($archive->getFromName('database.sql'))->toContain('Coca-Cola')
        ->and($this->backups->isLocked($zip))->toBeTrue();
});

it('uses the installer password when one is set, otherwise one tied to this server', function () {
    config(['rms.backup.password' => '']);
    $derived = $this->backups->password();
    expect(strlen($derived))->toBe(32)->and($derived)->not->toContain(config('app.key'));

    config(['rms.backup.password' => 'Written-Down-24-Chars-Pw']);
    expect($this->backups->password())->toBe('Written-Down-24-Chars-Pw');
});

it('restores a backup from another server only with that server\'s password', function () {
    config(['rms.backup.password' => 'other-server-password']);
    $zip = $this->backups->create('from the old server');
    $cokeId = $this->menu['coke']->id;
    Item::query()->forceDelete();

    config(['rms.backup.password' => 'this-server-password']);
    expect(fn () => $this->backups->restore($zip))->toThrow(RuntimeException::class, 'locked with a different backup password');
    expect(Item::query()->count())->toBe(0);

    $this->backups->restore($zip, 'other-server-password');
    expect(Item::query()->find($cokeId)?->name)->toBe('Coca-Cola');
});

it('still restores an older, unlocked backup', function () {
    $locked = $this->backups->create('to copy');
    // Rebuild the same backup without encryption, as backups were made before this change.
    $src = new ZipArchive;
    $src->open($locked);
    $src->setPassword($this->backups->password());
    $old = storage_path('backups/rms-backup-2026-11-01-020000.zip');
    $plain = new ZipArchive;
    $plain->open($old, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    for ($i = 0; $i < $src->numFiles; $i++) {
        $plain->addFromString($src->getNameIndex($i), $src->getFromIndex($i));
    }
    $plain->close();
    $src->close();

    $cokeId = $this->menu['coke']->id;
    Item::query()->forceDelete();
    expect($this->backups->isLocked($old))->toBeFalse();
    $this->backups->restore($old);
    expect(Item::query()->find($cokeId)?->name)->toBe('Coca-Cola');
});

it('shows the backup password on request', function () {
    config(['rms.backup.password' => 'Shown-Once-Password']);
    $this->artisan('rms:backup', ['--show-password' => true])->expectsOutputToContain('Shown-Once-Password')->assertSuccessful();
});
