<?php

namespace App\Modules\Settings\Services\Import;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/** Reads the first sheet of an .xlsx / .csv into rows keyed by snake_case headings. */
final class SpreadsheetReader implements ToCollection, WithHeadingRow
{
    private Collection $rows;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }

    /** @return Collection<int, array<string, mixed>> rows with heading keys; blank rows dropped; keys trimmed and snake-cased */
    public static function read(string $path): Collection
    {
        if (! is_file($path)) {
            throw new RuntimeException(__('settings::settings.import.errors.file_missing'));
        }
        $reader = new self;
        Excel::import($reader, $path);

        return ($reader->rows ?? collect())
            ->map(fn ($row) => collect($row instanceof Collection ? $row->all() : (array) $row)->mapWithKeys(fn ($v, $k) => [Str::snake(trim((string) $k)) => is_string($v) ? trim($v) : $v])->all())
            ->filter(fn (array $row) => collect($row)->contains(fn ($v) => $v !== null && $v !== ''))
            ->values();
    }

    /** "yes", "y", "true", "1", "x" → true */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['yes', 'y', 'true', '1', 'x', 'on'], true);
    }

    /** "12.50", "GHS 12.50", "12,5" → pesewas */
    public static function pesewas(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = str_replace(',', '.', preg_replace('/[^\d.,-]/', '', (string) $value));
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return (int) round((float) $clean * 100);
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $clean = str_replace(',', '.', preg_replace('/[^\d.,-]/', '', (string) $value));

        return is_numeric($clean) ? (float) $clean : null;
    }

    /** @return array<int, string> "a, b;c" → ['a', 'b', 'c'] */
    public static function list(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;|]/', (string) $value))));
    }
}
