<?php

use App\Modules\Settings\Filament\Pages\BackupsPage;
use App\Modules\Settings\Services\BackupService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Security review H2: the backup download / restore / delete screens must never reach a file
 * outside storage/backups, and only the owner may delete a backup.
 */
beforeEach(function () {
    File::cleanDirectory(storage_path('backups'));
    seedRoles();
    $this->branch = provisionTenant();
    demoMenu();
    $this->backups = app(BackupService::class);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->bait = storage_path('backups/../bait-secret.txt'); // a file that sits right next to the backups folder
    file_put_contents($this->bait, 'APP_KEY=secret');
});

afterEach(function () {
    File::cleanDirectory(storage_path('backups'));
    @unlink($this->bait);
});

it('never serves a file outside the backups folder, whatever the name looks like', function () {
    $this->actingAs($this->manager);
    $real = basename($this->backups->create());

    foreach ([
        '../.env', '../../.env', '/etc/passwd', base_path('.env.example'), '../bait-secret.txt',
        'storage/backups/../bait-secret.txt', '..%2F.env', 'rms-backup-2026-11-02-120000.zip/../../bait-secret.txt', '',
    ] as $name) {
        $this->get(route('settings.backups.download', ['file' => $name]))->assertNotFound();
        expect(fn () => $this->backups->resolve($name))->toThrow(RuntimeException::class);
    }

    $this->get(route('settings.backups.download', ['file' => $real]))->assertOk()->assertDownload($real);
    expect($this->backups->resolve($real))->toBe(realpath(storage_path('backups/'.$real)))
        ->and(is_file($this->bait))->toBeTrue();
});

it('ignores a backup-looking name that is really a path, and a zip that is not a backup', function () {
    copy(base_path('.env.example'), storage_path('backups/notes.zip'));

    expect(fn () => $this->backups->resolve('notes.zip'))->toThrow(RuntimeException::class)
        ->and(fn () => $this->backups->resolve(storage_path('backups/notes.zip')))->toThrow(RuntimeException::class)
        ->and(fn () => $this->backups->resolve('../backups/rms-backup-2026-11-02-120000.zip'))->toThrow(RuntimeException::class);
});

it('lets only the owner delete a backup', function () {
    $name = basename($this->backups->create());

    Livewire::actingAs($this->manager)->test(BackupsPage::class)->assertActionHidden('delete');
    expect(is_file(storage_path('backups/'.$name)))->toBeTrue();

    Livewire::actingAs($this->owner)->test(BackupsPage::class)->assertActionVisible('delete')
        ->callAction('delete', arguments: ['file' => $name])->assertHasNoErrors();
    expect(is_file(storage_path('backups/'.$name)))->toBeFalse();
});

it('refuses restore and delete to anyone but the owner even when called directly, not only hidden (security review N7)', function () {
    $name = basename($this->backups->create());

    foreach (['restore', 'uploadRestore', 'delete'] as $action) {
        Livewire::actingAs($this->manager)->test(BackupsPage::class)->assertActionHidden($action);
    }
    // The raw requests a tampered browser could send: mount the action, then run it.
    $page = Livewire::actingAs($this->manager)->test(BackupsPage::class);
    foreach (['delete', 'restore'] as $action) {
        try {
            $page->call('mountAction', $action, ['file' => $name])->set('mountedActions.0.data.confirm', 'RESTORE')->call('callMountedAction');
        } catch (Throwable) {
            // refused outright is fine too
        }
    }
    expect(is_file(storage_path('backups/'.$name)))->toBeTrue()
        ->and(BackupsPage::ownerOnly())->toBeFalse();
});
