<?php

use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Filament\Pages\BackupsPage;
use App\Modules\Settings\Services\BackupService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    File::cleanDirectory(storage_path('backups'));
    File::ensureDirectoryExists(storage_path('app/public'));
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->backups = app(BackupService::class);
});

afterEach(function () {
    File::cleanDirectory(storage_path('backups'));
    @unlink(storage_path('app/public/receipts/test-logo.png'));
    Carbon::setTestNow();
});

it('backs up the database and uploads, and restores them after data is lost', function () {
    File::ensureDirectoryExists(storage_path('app/public/receipts'));
    file_put_contents(storage_path('app/public/receipts/test-logo.png'), 'PNG-BYTES');
    $cokeId = $this->menu['coke']->id;

    $zip = $this->backups->create('test');
    expect($zip)->toStartWith(storage_path('backups/rms-backup-'))->and(is_file($zip))->toBeTrue();
    $archive = new ZipArchive;
    $archive->open($zip);
    $archive->setPassword($this->backups->password()); // backups are locked (compliance assessment)
    $manifest = json_decode($archive->getFromName('manifest.json'), true);
    expect($archive->locateName('database.sql'))->not->toBeFalse()
        ->and($archive->locateName('uploads/receipts/test-logo.png'))->not->toBeFalse()
        ->and($manifest['note'])->toBe('test')->and($manifest['driver'])->toBe('sqlite')->and($manifest['tables'])->toBeGreaterThan(20)
        ->and($archive->getFromName('database.sql'))->toContain('Coca-Cola');
    $archive->close();

    // Disaster: the menu is wiped and the logo deleted.
    Item::query()->forceDelete();
    unlink(storage_path('app/public/receipts/test-logo.png'));
    expect(Item::query()->count())->toBe(0);

    $safety = $this->backups->restore($zip);
    expect(is_file($safety))->toBeTrue()->and($safety)->not->toBe($zip)
        ->and(Item::query()->find($cokeId)?->name)->toBe('Coca-Cola')
        ->and(Item::query()->count())->toBe(3)
        ->and(file_get_contents(storage_path('app/public/receipts/test-logo.png')))->toBe('PNG-BYTES')
        ->and($this->backups->list())->toHaveCount(2);

    expect(fn () => $this->backups->restore(storage_path('backups/nope.zip')))->toThrow(RuntimeException::class, 'not found');
    file_put_contents(storage_path('backups/junk.zip'), 'not a zip');
    expect(fn () => $this->backups->restore(storage_path('backups/junk.zip')))->toThrow(RuntimeException::class);
});

it('prunes old backups but always keeps the newest three', function () {
    foreach ([40, 30, 20, 10, 1] as $daysAgo) {
        Carbon::setTestNow(now()->subDays($daysAgo));
        $path = $this->backups->create();
        touch($path, now()->timestamp);
    }
    Carbon::setTestNow();
    expect($this->backups->list())->toHaveCount(5);
    expect($this->backups->prune())->toBe(2) // 40 and 30 days old; 20 is kept as one of the newest three
        ->and($this->backups->list())->toHaveCount(3);
});

it('runs from the command line and copies to a USB folder', function () {
    $usb = storage_path('backups/usb-stick');
    File::ensureDirectoryExists($usb);
    $this->artisan('rms:backup', ['--copy-to' => $usb, '--note' => 'cli'])->expectsOutputToContain('Backup written')->expectsOutputToContain('Copied to')->assertExitCode(0);
    expect(File::files($usb))->toHaveCount(1);
    $this->artisan('rms:backups')->expectsOutputToContain('rms-backup-')->assertExitCode(0);
    $this->artisan('rms:backup', ['--copy-to' => '/nonexistent/usb'])->expectsOutputToContain('USB stick')->assertExitCode(1);

    Item::query()->forceDelete();
    $this->artisan('rms:restore', ['--force' => true])->expectsOutputToContain('Restored from')->assertExitCode(0);
    expect(Item::query()->count())->toBe(3);
    $this->artisan('rms:restore', ['file' => 'missing.zip', '--force' => true])->assertExitCode(1);
});

it('lists, takes, downloads and restores backups from the back office', function () {
    seedRoles();
    $owner = User::factory()->forBranch($this->branch)->create(['name' => 'Chimere']);
    $owner->assignRole(Role::Owner->value);
    $this->actingAs($owner);

    $this->get('/admin/backups')->assertOk()->assertSee('No backups yet');
    Livewire::test(BackupsPage::class)->callAction('backupNow')->assertHasNoErrors()->assertSee('rms-backup-');
    $backup = $this->backups->latest();

    $this->get(route('settings.backups.download', ['file' => $backup['name']]))->assertOk()->assertDownload($backup['name']);
    $this->get(route('settings.backups.download', ['file' => '../.env']))->assertNotFound();

    Item::query()->forceDelete();
    Livewire::test(BackupsPage::class)->callAction('restore', ['confirm' => 'nope'], ['file' => $backup['name']])->assertHasActionErrors(['confirm']);
    Livewire::test(BackupsPage::class)->callAction('restore', ['confirm' => 'RESTORE'], ['file' => $backup['name']])->assertHasNoErrors();
    expect(Item::query()->count())->toBe(3);

    $manager = User::factory()->forBranch($this->branch)->create();
    $manager->assignRole(Role::Manager->value);
    $this->flushSession();
    $this->actingAs($manager)->get('/admin/backups')->assertOk()->assertDontSee('>Restore<', false);
});
