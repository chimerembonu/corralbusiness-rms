<?php

namespace App\Modules\Settings\Services\Import;

/** What an import (or its preview) did or would do, row by row. */
final class ImportResult
{
    /** @var array<int, array{row: int, action: string, label: string, message: string}> */
    public array $rows = [];

    public function __construct(public readonly bool $dryRun) {}

    public function add(int $row, string $action, string $label, string $message = ''): void
    {
        $this->rows[] = ['row' => $row, 'action' => $action, 'label' => $label, 'message' => $message];
    }

    public function count(string $action): int
    {
        return count(array_filter($this->rows, fn ($r) => $r['action'] === $action));
    }

    public function hasErrors(): bool
    {
        return $this->count('error') > 0;
    }

    /** "12 new, 3 updated, 1 error" */
    public function summary(): string
    {
        $parts = [];
        foreach (['new', 'update', 'skip', 'error'] as $action) {
            if ($n = $this->count($action)) {
                $parts[] = trans_choice('settings::settings.import.counts.'.$action, $n, ['count' => $n]);
            }
        }

        return $parts ? implode(', ', $parts) : __('settings::settings.import.nothing');
    }
}
