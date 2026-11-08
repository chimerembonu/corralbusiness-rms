<?php

namespace App\Support\Licence;

use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The restaurant's licence (Complete Edition E1.2), checked on this server without internet.
 *
 *  - No licence: the till and back office work as today; extra (Complete Edition) features stay off.
 *  - Active: features up to the plan (plus any sold separately) can be switched on.
 *  - Ended: a grace period (config licence.grace_days) where everything still works, with a warning.
 *  - Then read-only: reports, backups and the licence page only, and tills take no NEW orders. This starts at
 *    a quiet hour (config licence.lock_hour, 04:00) the morning after the grace period, never in the middle
 *    of service, and an order that is already open can always be finished, paid and printed.
 */
class LicenceService
{
    public const SETTING = 'licence.code';

    /** @var array<string, Licence|null> tenant → licence, for this request */
    private array $memo = [];

    public function __construct(private readonly SettingsService $settings, private readonly TenantContext $context) {}

    public function current(): ?Licence
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId === null) {
            return null;
        }
        if (array_key_exists($tenantId, $this->memo)) {
            return $this->memo[$tenantId];
        }
        $code = $this->settings->get(self::SETTING);
        try {
            $licence = is_string($code) && $code !== '' ? LicenceCodec::read($code, $this->publicKeys()) : null;
        } catch (Throwable $e) {
            report($e); // a stored code that no longer checks out (e.g. a withdrawn key) counts as no licence
            $licence = null;
        }

        return $this->memo[$tenantId] = $licence;
    }

    /** Checks a code and installs it for this restaurant. Throws in plain words if it cannot be used. */
    public function install(string $code, ?User $by = null): Licence
    {
        $licence = $this->check($code);
        $this->settings->set(self::SETTING, $licence->code, tenantWide: true);
        unset($this->memo[(string) $this->context->tenantId()]);
        activity('settings')->event('licence_installed')->causedBy($by)
            ->withProperties(['licence' => $licence->id, 'plan' => $licence->plan, 'branches' => $licence->branches, 'expires' => $licence->expires->toDateString()])
            ->log('licence_installed');

        return $licence;
    }

    /** Checks a code for this restaurant without installing it. */
    public function check(string $code): Licence
    {
        $licence = LicenceCodec::read($code, $this->publicKeys());
        if ($licence->tenantId !== null && $licence->tenantId !== $this->context->tenantId()) {
            throw new LicenceException(__('settings::settings.licence.errors.other_restaurant', ['restaurant' => $licence->restaurant]));
        }

        return $licence;
    }

    public function status(): LicenceStatus
    {
        $licence = $this->current();
        if ($licence === null) {
            return LicenceStatus::None;
        }
        $now = $this->now($licence);
        if ($now->lte($licence->expires->endOfDay())) {
            return LicenceStatus::Active;
        }

        return $now->lt($this->readOnlyFrom($licence)) ? LicenceStatus::Grace : LicenceStatus::ReadOnly;
    }

    public function isReadOnly(): bool
    {
        return $this->status() === LicenceStatus::ReadOnly;
    }

    /** When reports-only starts: lock_hour on the morning after the last grace day. */
    public function readOnlyFrom(Licence $licence): CarbonImmutable
    {
        return $licence->expires->addDays((int) config('licence.grace_days', 14) + 1)->setTime((int) config('licence.lock_hour', 4), 0);
    }

    /** Whole days until the licence ends (0 on its last day, negative once ended). */
    public function daysLeft(): ?int
    {
        $licence = $this->current();

        return $licence ? (int) $this->now($licence)->startOfDay()->diffInDays($licence->expires, false) : null;
    }

    /** Is this feature covered: plan high enough or sold on top, and the licence not read-only. */
    public function allowsFeature(string $key, string $tier): bool
    {
        $licence = $this->current();
        if ($licence === null || $this->isReadOnly()) {
            return false;
        }
        $tiers = config('features.tiers', []);

        return ($tiers[$licence->plan] ?? 0) >= ($tiers[$tier] ?? PHP_INT_MAX) || in_array($key, $licence->extras, true);
    }

    /** Called before a till starts a new order. Orders already open are never stopped. */
    public function assertCanStartOrders(): void
    {
        if ($this->isReadOnly()) {
            throw new LicenceException(__('settings::settings.licence.till_paused'));
        }
    }

    public function branchesInUse(): int
    {
        return $this->context->tenantId() === null ? 0 : Branch::query()->count();
    }

    /** More branches than the licence covers (new branches are refused; existing ones keep working). */
    public function overBranchLimit(): bool
    {
        $licence = $this->current();

        return $licence !== null && $this->branchesInUse() > $licence->branches;
    }

    public function canAddBranch(): bool
    {
        $licence = $this->current();

        return $licence !== null && ! $this->isReadOnly() && $this->branchesInUse() < $licence->branches;
    }

    /** A one-line warning for the back office, or null when all is well. */
    public function warning(): ?string
    {
        $licence = $this->current();
        $t = fn (string $k, array $r = []) => __('settings::settings.licence.banner.'.$k, $r);

        return match ($this->status()) {
            LicenceStatus::None => null,
            LicenceStatus::ReadOnly => $t('read_only'),
            LicenceStatus::Grace => $t('grace', ['date' => $this->readOnlyFrom($licence)->subDay()->translatedFormat('j F Y')]),
            LicenceStatus::Active => match (true) {
                $this->daysLeft() <= (int) config('licence.warn_days', 30) => $t('ending', ['date' => $licence->expires->translatedFormat('j F Y'), 'days' => $this->daysLeft()]),
                $this->overBranchLimit() => $t('branches', ['used' => $this->branchesInUse(), 'allowed' => $licence->branches]),
                default => null,
            },
        };
    }

    /** @return array<string, string> */
    private function publicKeys(): array
    {
        return array_filter((array) config('licence.public_keys', []), fn ($key) => is_string($key) && $key !== '');
    }

    /**
     * The server's clock, but never earlier than the day the licence was issued, nor than the newest entry in
     * the history (audit log): setting the clock back does not make a licence last longer (E9.6 review L4).
     */
    private function now(Licence $licence): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $latest = $this->latestSeen();
        if ($latest && $latest->gt($now)) {
            $now = $latest;
        }

        return $now->lt($licence->issued) ? $licence->issued : $now;
    }

    /** When the clock says earlier than the newest history entry by more than a day: the clock was set back. */
    public function clockBehind(): ?CarbonImmutable
    {
        $latest = $this->latestSeen();

        return $latest && $latest->gt(CarbonImmutable::now()->addDay()) ? $latest : null;
    }

    private ?CarbonImmutable $latestSeen = null;

    private bool $latestSeenRead = false;

    private function latestSeen(): ?CarbonImmutable
    {
        if (! $this->latestSeenRead) {
            $this->latestSeenRead = true;
            try {
                $at = DB::table('activity_log')->orderByDesc('id')->value('created_at');
                $this->latestSeen = $at ? CarbonImmutable::parse($at) : null;
            } catch (Throwable) {
                $this->latestSeen = null;
            }
        }

        return $this->latestSeen;
    }
}
