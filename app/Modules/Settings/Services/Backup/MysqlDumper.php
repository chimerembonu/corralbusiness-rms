<?php

namespace App\Modules\Settings\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * MySQL / MariaDB: uses mysqldump and mysql when they are installed (the Docker image has them),
 * otherwise a plain-PHP dump that works anywhere.
 */
class MysqlDumper implements DatabaseDumper
{
    public function __construct(private readonly string $connection = 'mysql') {}

    public function dump(string $toFile): void
    {
        $c = config("database.connections.{$this->connection}");
        $binary = $this->binary('mysqldump');

        if ($binary) {
            $process = new Process([
                $binary, '--host='.$c['host'], '--port='.($c['port'] ?? 3306), '--user='.$c['username'],
                '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4',
                '--result-file='.$toFile, $c['database'],
            ], null, ['MYSQL_PWD' => (string) $c['password']], null, 1800);
            $process->run();
            if ($process->isSuccessful() && filesize($toFile) > 0) {
                return;
            }
            @unlink($toFile);
        }

        $this->phpDump($toFile);
    }

    public function restore(string $fromFile): void
    {
        $c = config("database.connections.{$this->connection}");
        $binary = $this->binary('mysql');

        if ($binary) {
            $process = Process::fromShellCommandline(
                escapeshellarg($binary).' --host='.escapeshellarg($c['host']).' --port='.escapeshellarg((string) ($c['port'] ?? 3306)).' --user='.escapeshellarg($c['username']).' --default-character-set=utf8mb4 '.escapeshellarg($c['database']).' < '.escapeshellarg($fromFile),
                null, ['MYSQL_PWD' => (string) $c['password']], null, 3600
            );
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
            throw new RuntimeException('mysql restore failed: '.trim($process->getErrorOutput()));
        }

        SqlFile::execute($fromFile, $this->connection);
    }

    /** Plain-PHP dump: CREATE TABLE from SHOW CREATE TABLE plus batched INSERTs. */
    private function phpDump(string $toFile): void
    {
        $db = DB::connection($this->connection);
        $out = fopen($toFile, 'w');
        fwrite($out, "-- CorralBusiness RMS backup (PHP dump)\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        foreach ($db->select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            $create = (array) $db->selectOne("SHOW CREATE TABLE `{$table}`");
            fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n".array_values($create)[1].";\n\n");

            $db->table($table)->orderByRaw('1')->chunk(500, function ($rows) use ($out, $table, $db) {
                $values = [];
                foreach ($rows as $r) {
                    $values[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $db->getPdo()->quote((string) $v), (array) $r)).')';
                }
                if ($values) {
                    $columns = implode(',', array_map(fn ($c) => "`{$c}`", array_keys((array) $rows->first())));
                    fwrite($out, "INSERT INTO `{$table}` ({$columns}) VALUES\n".implode(",\n", $values).";\n");
                }
            });
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }

    private function binary(string $name): ?string
    {
        foreach (['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/homebrew/opt/mysql-client/bin'] as $dir) {
            if (is_executable("{$dir}/{$name}")) {
                return "{$dir}/{$name}";
            }
        }
        $found = trim((string) @shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));

        return $found !== '' ? $found : null;
    }
}
