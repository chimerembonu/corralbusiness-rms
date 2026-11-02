<?php

namespace App\Modules\Settings\Services\Import;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Staff rows matched by phone, else by name. Owners are never created by import. */
class StaffImporter
{
    public function preview(string $path): ImportResult
    {
        return $this->run($path, true);
    }

    public function import(string $path): ImportResult
    {
        return $this->run($path, false);
    }

    private function run(string $path, bool $dryRun): ImportResult
    {
        $result = new ImportResult($dryRun);
        $rows = SpreadsheetReader::read($path);

        $work = function () use ($rows, $result) {
            foreach ($rows as $i => $row) {
                $this->importRow($i + 2, $row, $result);
            }
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $work();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($work);
        }

        return $result;
    }

    /** @param  array<string, mixed>  $row */
    private function importRow(int $rowNo, array $row, ImportResult $result): void
    {
        $name = trim((string) ($row['name'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? '')) ?: null;
        if ($name === '') {
            $result->add($rowNo, 'error', '?', __('settings::settings.import.errors.name_required'));

            return;
        }

        $roles = [];
        foreach (SpreadsheetReader::list($row['roles'] ?? '') as $roleName) {
            $role = Role::tryFrom(strtolower($roleName));
            if (! $role) {
                $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.bad_role', ['role' => $roleName, 'roles' => implode(', ', array_diff(Role::values(), [Role::Owner->value]))]));

                return;
            }
            if ($role === Role::Owner) {
                $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.no_owner'));

                return;
            }
            $roles[] = $role->value;
        }
        if ($roles === []) {
            $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.role_required'));

            return;
        }

        $pin = trim((string) ($row['pin'] ?? '')) ?: null;
        if ($pin !== null) {
            try {
                PinHasher::validate($pin);
            } catch (\InvalidArgumentException $e) {
                $result->add($rowNo, 'error', $name, $e->getMessage());

                return;
            }
        }

        $user = ($phone ? User::query()->where('phone', $phone)->first() : null)
            ?? User::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        $isNew = $user === null;
        $user ??= new User;

        if ($pin !== null) {
            $taken = User::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', Tenancy::tenantId())
                ->where('pin_lookup', PinHasher::lookup(Tenancy::tenantId(), $pin))->when(! $isNew, fn ($q) => $q->whereKeyNot($user->getKey()))->exists();
            if ($taken) {
                $result->add($rowNo, 'error', $name, __('staff::staff.errors.pin_in_use'));

                return;
            }
        } elseif ($isNew) {
            $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.pin_required'));

            return;
        }

        $email = trim((string) ($row['email'] ?? '')) ?: null;
        if ($email && User::query()->withoutGlobalScope(TenantScope::class)->where('email', $email)->whereKeyNot($user->getKey() ?? '')->exists()) {
            $result->add($rowNo, 'error', $name, __('settings::settings.import.errors.email_taken', ['email' => $email]));

            return;
        }

        $user->fill([
            'name' => $name,
            'phone' => $phone ?? $user->phone,
            'job_title' => trim((string) ($row['job_title'] ?? '')) ?: $user->job_title,
            'email' => $email ?? $user->email,
            'started_on' => filled($row['started_on'] ?? null) ? $this->date($row['started_on']) : $user->started_on,
            'is_active' => true,
        ]);
        if ($pin !== null) {
            $user->pin = $pin;
        }
        if ($isNew) {
            $user->tenant_id = Tenancy::tenantId();
            $user->branch_id = Tenancy::branchId();
        }
        $user->save();
        $user->syncRoles($roles);

        $result->add($rowNo, $isNew ? 'new' : 'update', $name, implode(', ', array_map(fn ($r) => Role::from($r)->label(), $roles)).($pin ? ' · '.__('settings::settings.import.pin_set') : ''));
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value)) { // Excel serial date
            return Carbon::createFromTimestamp(((int) $value - 25569) * 86400)->toDateString();
        }
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
