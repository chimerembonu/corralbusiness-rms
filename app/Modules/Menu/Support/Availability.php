<?php

namespace App\Modules\Menu\Support;

use Carbon\CarbonInterface;

/**
 * When a category/item can be ordered. Stored as JSON:
 *   {"days": [1,2,3,4,5,6,7], "from": "06:00", "to": "14:00"}   (ISO days: 1 = Monday … 7 = Sunday)
 * null / empty = always available. An overnight window (from 18:00 to 02:00) is supported.
 */
final readonly class Availability
{
    /** @param  array<int, int>|null  $days */
    public function __construct(
        public ?array $days = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public static function always(): self
    {
        return new self;
    }

    /** @param  array<string, mixed>|null  $data */
    public static function fromArray(?array $data): self
    {
        if (! $data) {
            return self::always();
        }

        $days = isset($data['days']) && $data['days'] !== [] ? array_map('intval', array_values($data['days'])) : null;

        return new self($days, $data['from'] ?: null, $data['to'] ?: null);
    }

    public function isAlways(): bool
    {
        return $this->days === null && $this->from === null && $this->to === null;
    }

    public function isAvailableAt(CarbonInterface $at): bool
    {
        if ($this->days !== null && ! in_array($at->dayOfWeekIso, $this->days, true)) {
            return false;
        }

        if ($this->from === null || $this->to === null) {
            return true;
        }

        $now = $at->format('H:i');

        return $this->from <= $this->to
            ? ($now >= $this->from && $now <= $this->to)
            : ($now >= $this->from || $now <= $this->to); // overnight
    }

    /** @return array<string, mixed>|null */
    public function toArray(): ?array
    {
        return $this->isAlways() ? null : ['days' => $this->days, 'from' => $this->from, 'to' => $this->to];
    }

    public function describe(): string
    {
        if ($this->isAlways()) {
            return __('menu::menu.availability.always');
        }

        $parts = [];
        if ($this->days !== null) {
            $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
            $parts[] = implode(', ', array_map(fn (int $d) => $labels[$d] ?? $d, $this->days));
        }
        if ($this->from !== null && $this->to !== null) {
            $parts[] = "{$this->from}–{$this->to}";
        }

        return implode(' · ', $parts);
    }
}
