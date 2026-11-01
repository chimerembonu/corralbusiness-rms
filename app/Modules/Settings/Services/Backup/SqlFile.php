<?php

namespace App\Modules\Settings\Services\Backup;

use Illuminate\Support\Facades\DB;

/** Runs a .sql file statement by statement (the PHP fallback for restores). */
final class SqlFile
{
    public static function execute(string $file, string $connection): void
    {
        $db = DB::connection($connection);
        $handle = fopen($file, 'r');
        $buffer = '';
        $inString = false;
        $quote = '';

        while (($line = fgets($handle)) !== false) {
            if ($buffer === '' && ! $inString && (str_starts_with($line, '--') || str_starts_with($line, '/*') || trim($line) === '')) {
                continue;
            }
            $buffer .= $line;
            // Track quotes so a ";" inside a value does not end the statement.
            $len = strlen($line);
            for ($i = 0; $i < $len; $i++) {
                $ch = $line[$i];
                if ($inString) {
                    if ($ch === '\\') {
                        $i++;
                    } elseif ($ch === $quote) {
                        if (($line[$i + 1] ?? '') === $quote) {
                            $i++;
                        } else {
                            $inString = false;
                        }
                    }
                } elseif ($ch === "'" || $ch === '"') {
                    $inString = true;
                    $quote = $ch;
                }
            }
            if (! $inString && str_ends_with(rtrim($line), ';')) {
                $db->unprepared($buffer);
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $db->unprepared($buffer);
        }
        fclose($handle);
    }
}
