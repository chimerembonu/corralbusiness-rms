<?php

namespace App\Modules\Settings\Services\Backup;

use Illuminate\Support\Facades\DB;

/**
 * SQLite (tests and small installs). The dump holds the data only: on restore every table is
 * emptied and refilled inside one transaction with deferred foreign keys. The schema itself comes
 * from the app's migrations, which run on every start.
 */
class SqliteDumper implements DatabaseDumper
{
    public function __construct(private readonly string $connection = 'sqlite') {}

    public function dump(string $toFile): void
    {
        $db = DB::connection($this->connection);
        $pdo = $db->getPdo();
        $out = fopen($toFile, 'w');
        fwrite($out, "-- CorralBusiness RMS backup (SQLite, data only)\nPRAGMA defer_foreign_keys=ON;\n\n");

        $tables = $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        foreach ($tables as $t) {
            fwrite($out, "DELETE FROM \"{$t->name}\";\n");
        }
        fwrite($out, "\n");
        foreach ($tables as $t) {
            foreach ($db->table($t->name)->get() as $r) {
                $row = (array) $r;
                $cols = implode(',', array_map(fn ($c) => "\"{$c}\"", array_keys($row)));
                $vals = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row));
                fwrite($out, "INSERT INTO \"{$t->name}\" ({$cols}) VALUES ({$vals});\n");
            }
        }
        fclose($out);
    }

    public function restore(string $fromFile): void
    {
        DB::connection($this->connection)->transaction(fn () => SqlFile::execute($fromFile, $this->connection));
    }
}
