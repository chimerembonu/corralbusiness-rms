<?php

namespace App\Support\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tamper-evident audit log (Complete Edition E9.4).
 *
 * Every activity_log entry is "sealed" shortly after it is written (each minute by the scheduler, and before a
 * day is closed): it gets the next number in the chain, a fingerprint of its contents, and a link made from
 * that fingerprint and the link of the entry before it. Sealing runs apart from the tills, so it never slows
 * an order down.
 *
 * check() walks the chain: an entry whose contents were changed, a missing entry (numbers skip) or an entry
 * slipped in (a link does not match) is reported. Someone with full access to the server could rebuild the
 * whole chain; that is why the Z report prints the chain's code of the moment (a "history check code"), and
 * the owner can test an old printed code against the chain at any time on Settings → History check.
 *
 * Personal details removed on purpose (customer privacy, E9.3) go through redact(): the removal is listed in
 * audit_redactions, and the audit entry recording the removal carries a fingerprint of that list.
 *
 * Each entry also has a second fingerprint, core_hash, that leaves out the personal details of customer,
 * address and order entries, and the link covers both (E9.6 review H1). A removal of personal details changes
 * only the first, so even a forged removal record cannot hide a changed amount, reason or person. The check
 * also tests every printed Z-report code it can find, so a rebuilt chain shows up without anyone typing a
 * code. What it cannot see: someone with full access to the server who deletes the very newest entries
 * (after the last Z report) and resets the end of the chain; the next Z report's code is the safeguard.
 */
final class AuditChain
{
    public const NAME = 'store';

    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const BATCH = 500;

    /** Personal fields (customer privacy, E9.3): the only fields a recorded removal may change. */
    public const PERSONAL_KEYS = ['name', 'phone', 'phone_digits', 'email', 'tin', 'notes', 'tags', 'address', 'label',
        'customer_name', 'customer_phone', 'delivery_address', 'delivery_notes'];

    /** Entries about these may have personal details removed; nothing else ever is. */
    private const REDACTABLE_SUBJECTS = [
        'App\\Modules\\Customers\\Models\\Customer', 'App\\Modules\\Customers\\Models\\CustomerAddress', 'App\\Modules\\Orders\\Models\\Order'];

    /** The audit entries that record a removal (CustomerPrivacyService). */
    private const REDACTION_ENTRIES = ['customer_anonymised', 'order_details_cleaned_up'];

    /** Seal every entry not sealed yet. @return int how many were sealed */
    public function seal(): int
    {
        $total = 0;
        do {
            $sealed = DB::transaction(function () {
                $head = $this->head(lock: true);
                $rows = DB::table('activity_log')->whereNull('chain_seq')->orderBy('id')->limit(self::BATCH)->get();
                $seq = (int) $head->last_seq;
                $hash = (string) $head->last_hash;
                foreach ($rows as $row) {
                    $seq++;
                    $content = self::contentHash($row, $seq);
                    $core = self::coreHash($row, $seq);
                    $hash = self::link($hash, $content, $core);
                    DB::table('activity_log')->where('id', $row->id)->update(['chain_seq' => $seq, 'content_hash' => $content, 'core_hash' => $core, 'chain_hash' => $hash]);
                }
                if ($rows->isNotEmpty()) {
                    DB::table('audit_chain')->where('id', $head->id)->update(['last_seq' => $seq, 'last_hash' => $hash, 'updated_at' => now()]);
                }

                return $rows->count();
            });
            $total += $sealed;
        } while ($sealed === self::BATCH);

        return $total;
    }

    /**
     * The code printed on a Z report: the chain's number and the start of its link at that moment.
     * Everything is sealed first, so the code covers every entry written so far.
     */
    public function code(): string
    {
        $this->seal();
        $head = $this->head();

        return self::formatCode((int) $head->last_seq, (string) $head->last_hash);
    }

    public static function formatCode(int $seq, string $hash): string
    {
        return $seq.'-'.strtoupper(substr($hash, 0, 10));
    }

    /**
     * Is a code printed earlier (on a Z report) still part of the chain?
     *
     * @return bool|null null when the code cannot be read
     */
    public function codeMatches(string $code): ?bool
    {
        if (! preg_match('/^\s*#?(\d+)\s*-\s*([0-9a-f]{10})\s*$/i', $code, $m)) {
            return null;
        }
        if ((int) $m[1] === 0) {
            return true; // printed before anything was logged
        }
        $hash = DB::table('activity_log')->where('chain_seq', (int) $m[1])->value('chain_hash');

        return $hash !== null && strtoupper(substr($hash, 0, 10)) === strtoupper($m[2]);
    }

    /**
     * Walk the whole chain.
     *
     * @return array{checked: int, unsealed: int, problems: list<array{seq: int|null, id: int|null, kind: string}>, code: string}
     */
    public function check(): array
    {
        $problems = [];
        $redactions = $this->redactionsByEntry($problems);
        $expected = 1;
        $previous = self::GENESIS;
        $checked = 0;

        DB::table('activity_log')->whereNotNull('chain_seq')->orderBy('chain_seq')
            ->chunk(1000, function ($rows) use (&$problems, &$expected, &$previous, &$checked, $redactions) {
                foreach ($rows as $row) {
                    $seq = (int) $row->chain_seq;
                    if ($seq !== $expected) {
                        $problems[] = ['seq' => $expected, 'id' => null, 'kind' => 'missing', 'to' => $seq - 1];
                        $previous = null; // the link before a gap cannot be checked
                    }
                    if ($previous !== null && self::link($previous, (string) $row->content_hash, (string) $row->core_hash) !== $row->chain_hash) {
                        $problems[] = ['seq' => $seq, 'id' => (int) $row->id, 'kind' => 'link'];
                    }
                    $current = self::contentHash($row, $seq);
                    $changed = self::coreHash($row, $seq) !== $row->core_hash // never excused: a removal only touches personal details
                        || ($current !== $row->content_hash && ! self::redactedTo((string) $row->content_hash, $current, $redactions[(int) $row->id] ?? []));
                    if ($changed) {
                        $problems[] = ['seq' => $seq, 'id' => (int) $row->id, 'kind' => 'changed'];
                    }
                    $previous = $row->chain_hash;
                    $expected = $seq + 1;
                    $checked++;
                }
            });

        $head = $this->head();
        if ((int) $head->last_seq !== $expected - 1 || ($checked > 0 && $previous !== null && $previous !== $head->last_hash)) {
            $problems[] = ['seq' => $expected, 'id' => null, 'kind' => 'end', 'to' => (int) $head->last_seq];
        }
        foreach ($this->printedCodes() as $number => $code) {
            if ($this->codeMatches($code) === false) {
                $problems[] = ['seq' => null, 'id' => null, 'kind' => 'z_code', 'to' => $number];
            }
        }
        $unsealed = DB::table('activity_log')->whereNull('chain_seq')->count();

        DB::table('audit_chain')->where('id', $head->id)->update([
            'verified_at' => now(), 'verified_count' => $checked, 'problems' => json_encode($problems), 'updated_at' => now(),
        ]);

        return ['checked' => $checked, 'unsealed' => $unsealed, 'problems' => $problems, 'code' => self::formatCode((int) $head->last_seq, (string) $head->last_hash)];
    }

    /** One problem in plain words. */
    public static function describe(array $problem): string
    {
        return __('settings::settings.history.kinds.'.$problem['kind'], [
            'seq' => $problem['seq'] ?? '?', 'to' => $problem['to'] ?? '?', 'id' => $problem['id'] ?? '?',
        ]);
    }

    /** The last check's result (null before the first check). */
    public function lastCheck(): ?object
    {
        return DB::table('audit_chain')->where('name', self::NAME)->first(['verified_at', 'verified_count', 'problems', 'last_seq', 'last_hash']);
    }

    /**
     * Blank personal details in the audit entries of these records. Entries already sealed are listed as
     * removed on purpose. Returns the list, to be fingerprinted into the entry that records the removal
     * (redactionDigest) and then stored (storeRedactions).
     *
     * @param  callable(array): array  $blank  receives an entry's attribute_changes / properties and returns them cleaned
     * @return list<array{activity_id: int, old_content_hash: string, new_content_hash: string}>
     */
    public function redact(string $subjectType, array $subjectIds, callable $blank): array
    {
        if ($subjectIds === []) {
            return [];
        }
        $this->seal();
        $list = [];
        foreach (array_chunk($subjectIds, 500) as $ids) {
            foreach (DB::table('activity_log')->where('subject_type', $subjectType)->whereIn('subject_id', $ids)->orderBy('id')->get() as $row) {
                $changes = self::decode($row->attribute_changes);
                $properties = self::decode($row->properties);
                $newChanges = $blank($changes);
                $newProperties = $blank($properties);
                if ($newChanges === $changes && $newProperties === $properties) {
                    continue;
                }
                DB::table('activity_log')->where('id', $row->id)->update([
                    'attribute_changes' => $newChanges === [] ? null : json_encode($newChanges),
                    'properties' => $newProperties === [] ? null : json_encode($newProperties),
                ]);
                if ($row->chain_seq !== null) {
                    $fresh = DB::table('activity_log')->where('id', $row->id)->first();
                    $list[] = ['activity_id' => (int) $row->id, 'old_content_hash' => self::contentHash($row, (int) $row->chain_seq), 'new_content_hash' => self::contentHash($fresh, (int) $row->chain_seq)];
                }
            }
        }

        return $list;
    }

    /** Fingerprint of a removal list, kept in the properties of the entry that records the removal. */
    public static function redactionDigest(array $list): string
    {
        $lines = array_map(fn (array $r) => $r['activity_id'].':'.$r['old_content_hash'].':'.$r['new_content_hash'], $list);
        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    public function storeRedactions(array $list, ?int $redactionActivityId, ?string $tenantId): void
    {
        $now = now();
        foreach (array_chunk($list, 500) as $chunk) {
            DB::table('audit_redactions')->insert(array_map(fn (array $r) => $r + [
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'redaction_activity_id' => $redactionActivityId, 'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    /**
     * Removals grouped by entry, keeping only those backed by an untouched removal record.
     *
     * @return array<int, list<object>>
     */
    private function redactionsByEntry(array &$problems): array
    {
        $byEntry = [];
        foreach (DB::table('audit_redactions')->orderBy('created_at')->orderBy('id')->get()->groupBy('redaction_activity_id') as $activityId => $rows) {
            $entry = $activityId ? DB::table('activity_log')->where('id', $activityId)->first() : null;
            $properties = $entry ? self::decode($entry->properties) : [];
            $list = $rows->map(fn ($r) => ['activity_id' => (int) $r->activity_id, 'old_content_hash' => $r->old_content_hash, 'new_content_hash' => $r->new_content_hash])->all();
            // Accepted only from a sealed removal entry, written after every entry it lists, about customers or orders.
            $listed = DB::table('activity_log')->whereIn('id', array_column($list, 'activity_id'))->get(['id', 'chain_seq', 'subject_type']);
            $genuine = $entry && $entry->chain_seq !== null && $entry->log_name === 'customers'
                && in_array($entry->description, self::REDACTION_ENTRIES, true)
                && $listed->every(fn ($r) => $r->chain_seq !== null && (int) $r->chain_seq < (int) $entry->chain_seq && self::redactable($r->subject_type));
            if (! $genuine || ($properties['redactions'] ?? null) !== self::redactionDigest($list)) {
                $problems[] = ['seq' => null, 'id' => $activityId ? (int) $activityId : null, 'kind' => 'redaction'];

                continue;
            }
            foreach ($rows as $row) {
                $byEntry[(int) $row->activity_id][] = $row;
            }
        }

        return $byEntry;
    }

    /** Was $from turned into $to by recorded removals, one after another? */
    private static function redactedTo(string $from, string $to, array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->old_content_hash === $from) {
                $from = $step->new_content_hash;
            }
        }

        return $from === $to;
    }

    private function head(bool $lock = false): object
    {
        $query = DB::table('audit_chain')->where('name', self::NAME);
        $head = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($head) {
            return $head;
        }
        DB::table('audit_chain')->insertOrIgnore(['id' => (string) Str::uuid7(), 'name' => self::NAME, 'last_seq' => 0, 'last_hash' => self::GENESIS, 'created_at' => now(), 'updated_at' => now()]);
        $query = DB::table('audit_chain')->where('name', self::NAME);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private static function link(string $previous, string $content, string $core): string
    {
        return hash('sha256', $previous.'|'.$content.'|'.$core);
    }

    private static function redactable(?string $subjectType): bool
    {
        return in_array($subjectType, self::REDACTABLE_SUBJECTS, true);
    }

    /** History codes printed on Z reports, by day-close number (any restaurant on this server). @return array<string, string> */
    private function printedCodes(): array
    {
        if (! DB::getSchemaBuilder()->hasTable('day_closes')) {
            return [];
        }
        $codes = [];
        foreach (DB::table('day_closes')->whereNotNull('report')->orderBy('closed_at')->get(['number', 'report']) as $close) {
            $code = self::decode($close->report)['history_code'] ?? null;
            if (is_string($code) && $code !== '') {
                $codes[(string) $close->number] = $code;
            }
        }

        return $codes;
    }

    /** Fingerprint without the personal details of customer, address and order entries (unchanged by a removal). */
    private static function coreHash(object $row, int $seq): string
    {
        if (! self::redactable($row->subject_type)) {
            return self::contentHash($row, $seq);
        }
        $core = clone $row;
        $core->attribute_changes = json_encode(self::withoutPersonal(self::decode($row->attribute_changes)));
        $core->properties = json_encode(self::withoutPersonal(self::decode($row->properties)));

        return hash('sha256', 'core|'.self::contentHash($core, $seq));
    }

    private static function withoutPersonal(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withoutPersonal($value);
            } elseif (in_array($key, self::PERSONAL_KEYS, true) && $value !== null) {
                $data[$key] = '*';
            }
        }

        return $data;
    }

    /** Fingerprint of an entry as stored (read straight from the table, so it is the same every time). */
    private static function contentHash(object $row, int $seq): string
    {
        return hash('sha256', json_encode([
            'seq' => $seq,
            'tenant_id' => $row->tenant_id,
            'branch_id' => $row->branch_id,
            'log_name' => $row->log_name,
            'description' => $row->description,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'event' => $row->event,
            'causer_type' => $row->causer_type,
            'causer_id' => $row->causer_id,
            'attribute_changes' => self::canonical(self::decode($row->attribute_changes)),
            'properties' => self::canonical(self::decode($row->properties)),
            'batch_uuid' => $row->batch_uuid,
            'created_at' => $row->created_at === null ? null : substr((string) $row->created_at, 0, 19),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function decode(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $value = is_array($json) ? $json : json_decode((string) $json, true);

        return is_array($value) ? $value : ['_' => $value];
    }

    /** Same order every time (the database may store JSON keys in its own order). */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
