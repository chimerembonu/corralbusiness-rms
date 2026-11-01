<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Services\Import\Importers;
use App\Modules\Settings\Services\Import\ImportTemplates;
use App\Support\Tenancy\StoreTenantResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/** `php artisan rms:import menu menu.xlsx` — import a filled-in template (add --dry-run to only check it). */
class ImportCommand extends Command
{
    protected $signature = 'rms:import {type : menu | ingredients | staff} {file? : The .xlsx or .csv to import} {--dry-run : Check the file without changing anything} {--template= : Write an empty template to this path instead}';

    protected $description = 'Import menu items, ingredients or staff from a spreadsheet';

    public function handle(StoreTenantResolver $store): int
    {
        $type = $this->argument('type');
        if (! in_array($type, ImportTemplates::TYPES, true)) {
            $this->components->error('Type must be one of: '.implode(', ', ImportTemplates::TYPES));

            return self::FAILURE;
        }

        if ($to = $this->option('template')) {
            Excel::store(ImportTemplates::export($type), basename($to), null, null, []);
            $stored = storage_path('app/private/'.basename($to));
            if (! is_file($stored)) {
                $stored = storage_path('app/'.basename($to));
            }
            @rename($stored, $to);
            $this->components->info("Template written to {$to}");

            return self::SUCCESS;
        }

        if (! Tenancy::tenantId()) {
            $tenant = $store->tenant();
            if (! $tenant) {
                $this->components->error('No restaurant set up yet. Run rms:setup first.');

                return self::FAILURE;
            }
            Tenancy::set($tenant, $store->branch($tenant));
        }

        $file = $this->argument('file');
        if (! $file || ! is_file($file)) {
            $this->components->error('File not found: '.$file);

            return self::FAILURE;
        }

        try {
            $result = $this->option('dry-run') ? Importers::preview($type, $file) : Importers::import($type, $file);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Row', 'Action', 'What', 'Detail'], array_map(fn ($r) => [$r['row'], $r['action'], $r['label'], $r['message']], $result->rows));
        $this->components->info(($result->dryRun ? 'Preview: ' : 'Imported: ').$result->summary());

        return $result->hasErrors() && $result->dryRun ? self::FAILURE : self::SUCCESS;
    }
}
