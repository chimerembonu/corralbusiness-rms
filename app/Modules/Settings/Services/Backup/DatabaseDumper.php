<?php

namespace App\Modules\Settings\Services\Backup;

/** Writes the whole database as SQL to a file, and loads such a file back. */
interface DatabaseDumper
{
    public function dump(string $toFile): void;

    public function restore(string $fromFile): void;
}
