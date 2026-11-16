<?php

use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Services\BackupService;
use App\Support\Errors\UserFacingException;
use Illuminate\Support\Facades\File;

/*
 * Test report 9 Nov 2026, H11: in the restore drill on MySQL, a backup with a few damaged bytes was
 * accepted, the restore stopped part-way, and the live database was left without customers and orders.
 * A backup now carries a fingerprint of its database file and is checked before anything is touched; if a
 * restore still fails part-way (e.g. an older backup with no fingerprint), the state from just before is put back.
 */
beforeEach(function () {
    File::cleanDirectory(storage_path('backups'));
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->backups = app(BackupService::class);
});

afterEach(fn () => File::cleanDirectory(storage_path('backups')));

/** Rewrite database.sql inside a locked backup, keeping (or dropping) its fingerprint. */
function tamperBackup(string $zipPath, callable $change, bool $keepFingerprint = true): void
{
    $password = app(BackupService::class)->password();
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->setPassword($password);
    $sql = $change((string) $zip->getFromName('database.sql'));
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    if (! $keepFingerprint) {
        unset($manifest['sha256']);
    }
    $zip->addFromString('database.sql', $sql);
    $zip->addFromString('manifest.json', json_encode($manifest));
    $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256, $password);
    $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256, $password);
    $zip->close();
}

it('fingerprints the database file of every backup', function () {
    $zip = new ZipArchive;
    $zip->open($this->backups->create('test'));
    $zip->setPassword($this->backups->password());
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

    expect($manifest['sha256'])->toBe(hash('sha256', (string) $zip->getFromName('database.sql')));
});

it('refuses a damaged backup before touching the database', function () {
    $path = $this->backups->create('good');
    tamperBackup($path, fn (string $sql) => str_replace('Coca-Cola', 'Coca-Kola', $sql));
    Item::query()->where('name', 'Bottled Water')->delete(); // a change made after the backup
    $before = Item::query()->count();
    $files = count(File::files(storage_path('backups')));

    expect(fn () => $this->backups->restore($path))->toThrow(UserFacingException::class, 'This backup is damaged');

    expect(Item::query()->count())->toBe($before)
        ->and(Item::query()->where('name', 'Coca-Cola')->exists())->toBeTrue()
        ->and(count(File::files(storage_path('backups'))))->toBe($files); // nothing touched, no safety copy needed
});

it('puts everything back when a restore fails part-way', function () {
    $path = $this->backups->create('old style');
    // An older backup without a fingerprint, broken half-way through.
    tamperBackup($path, fn (string $sql) => substr($sql, 0, intdiv(strlen($sql), 2))."\nTHIS IS NOT SQL;\n", keepFingerprint: false);
    Item::query()->where('name', 'Bottled Water')->delete();
    $before = Item::query()->pluck('name')->sort()->values()->all();

    expect(fn () => $this->backups->restore($path))->toThrow(UserFacingException::class, 'put back as it was just before');

    expect(Item::query()->pluck('name')->sort()->values()->all())->toBe($before);
});

it('still restores a good backup', function () {
    $path = $this->backups->create('good');
    Item::query()->where('name', 'Bottled Water')->delete();

    $this->backups->restore($path);

    expect(Item::query()->where('name', 'Bottled Water')->exists())->toBeTrue();
});
