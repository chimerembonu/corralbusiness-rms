<?php

use App\Modules\Settings\Filament\Pages\ImportPage;
use App\Modules\Settings\Services\Import\ImportTemplates;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Re-review: an uploaded staff spreadsheet, PINs and all, stayed on the server forever, and the import
 * preview echoed every PIN. The file is removed once read and the preview only says "PIN set".
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
    $this->dir = storage_path('app/private/imports');
    File::ensureDirectoryExists($this->dir);
    $this->before = collect(File::files($this->dir))->map->getFilename()->all();
});

afterEach(function () {
    foreach (File::files($this->dir) as $f) {
        if (! in_array($f->getFilename(), $this->before, true)) {
            @unlink($f->getPathname());
        }
    }
});

function staffCsv(): UploadedFile
{
    $h = fopen('php://temp', 'w+');
    fputcsv($h, ImportTemplates::headings('staff'));
    fputcsv($h, ['Yaw Asante', '024 100 0003', 'waiter', '2001', 'Waiter', '', '']);
    rewind($h);
    $csv = stream_get_contents($h);
    fclose($h);

    return UploadedFile::fake()->createWithContent('staff-hygiene.csv', $csv);
}

it('deletes the spreadsheet after importing it and never shows a PIN in the preview', function () {
    $page = Livewire::test(ImportPage::class)->fillForm(['type' => 'staff', 'file' => staffCsv()])->call('importFile');

    expect(User::query()->where('name', 'Yaw Asante')->exists())->toBeTrue('import did not run: '.json_encode($page->get('summary')))
        ->and(json_encode($page->get('preview')))->not->toContain('2001')
        ->and(json_encode($page->get('preview')))->toContain('PIN set')
        ->and(collect(File::files($this->dir))->map->getFilename()->filter(fn ($n) => str_contains($n, 'staff-hygiene'))->all())->toBe([]);
});
