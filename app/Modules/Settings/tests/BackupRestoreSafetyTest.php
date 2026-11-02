<?php

use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Services\BackupService;
use Illuminate\Support\Facades\File;

/*
 * Security review H3: a crafted backup zip must not be able to write outside the restore folder
 * or drop runnable code under the web-served uploads folder ("zip-slip").
 */
beforeEach(function () {
    File::cleanDirectory(storage_path('backups'));
    File::ensureDirectoryExists(storage_path('app/public'));
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->backups = app(BackupService::class);
    $this->planted = [storage_path('planted.txt'), storage_path('app/public/shell.php'), base_path('planted.txt')];
});

afterEach(function () {
    File::cleanDirectory(storage_path('backups'));
    foreach ($this->planted as $file) {
        @unlink($file);
    }
});

function craftedBackup(array $entries): string
{
    $path = storage_path('backups/rms-backup-2026-11-02-120000.zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('database.sql', "-- nothing\n");
    $zip->addFromString('manifest.json', json_encode(['driver' => 'sqlite']));
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

it('refuses a backup whose entries point outside the restore folder or carry code', function () {
    $itemsBefore = Item::query()->count();

    foreach ([
        ['../../planted.txt' => 'x'],
        ['uploads/../../planted.txt' => 'x'],
        ['/planted.txt' => 'x'],
        ['uploads/shell.php' => '<?php system($_GET["c"]);'],
        ['uploads/logo.php.png/../shell.php' => 'x'],
        ['uploads\\..\\planted.txt' => 'x'],
        ['extra/notes.txt' => 'x'],
        ['uploads/logo.phtml' => 'x'],
    ] as $entries) {
        $zip = craftedBackup($entries);
        expect(fn () => $this->backups->restore($zip))->toThrow(RuntimeException::class, 'does not belong in a backup');
    }

    foreach ($this->planted as $file) {
        expect(is_file($file))->toBeFalse($file.' must not exist');
    }
    expect(File::glob(storage_path('backups/.restore-*')))->toBe([])
        ->and(Item::query()->count())->toBe($itemsBefore)
        ->and($this->backups->list())->toHaveCount(1); // no safety backup was taken for a refused file
});

it('still restores a genuine backup with image uploads', function () {
    expect($this->backups->isSafeEntry('uploads/receipts/logo.png'))->toBeTrue()
        ->and($this->backups->isSafeEntry('uploads/items/'))->toBeTrue()
        ->and($this->backups->isSafeEntry('uploads/items/jollof.JPG'))->toBeTrue()
        ->and($this->backups->isSafeEntry('database.sql'))->toBeTrue()
        ->and($this->backups->isSafeEntry('uploads/items/jollof.php'))->toBeFalse()
        ->and($this->backups->isSafeEntry('database.sql/../x'))->toBeFalse();

    File::ensureDirectoryExists(storage_path('app/public/receipts'));
    file_put_contents(storage_path('app/public/receipts/test-logo.png'), 'PNG-BYTES');
    $real = $this->backups->create();
    unlink(storage_path('app/public/receipts/test-logo.png'));

    $this->backups->restore($real);
    expect(file_get_contents(storage_path('app/public/receipts/test-logo.png')))->toBe('PNG-BYTES');
    unlink(storage_path('app/public/receipts/test-logo.png'));
});

it('tells nginx never to run PHP from the uploads path', function () {
    $conf = file_get_contents(base_path('docker/nginx/default.conf'));
    $deny = strpos($conf, 'location ~* ^/storage/.*\.php$ { deny all; }');
    $php = strpos($conf, 'location ~ \.php$');

    expect($deny)->not->toBeFalse()->and($php)->not->toBeFalse()->and($deny)->toBeLessThan($php);
});
