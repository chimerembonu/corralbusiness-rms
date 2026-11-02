<?php

namespace App\Modules\Pos\Services;

/**
 * Training mode: the till rings practice orders that never count. The state lives on the till row
 * (tills.training_mode), switched by a manager and cleared at day close; nothing in the browser
 * can turn it on (re-review of H1: the old cookie could be copied and replayed).
 */
class TrainingMode
{
    private ?bool $on = null;

    public function __construct(private readonly CurrentTill $till) {}

    public function isOn(): bool
    {
        return $this->on ??= (bool) ($this->till->get()?->training_mode ?? false);
    }

    /** Switch this device's till. Without a till (a tablet that never chose one) only this request is affected. */
    public function set(bool $on): void
    {
        $this->till->get()?->forceFill(['training_mode' => $on])->save();
        $this->on = $on;
    }

    public function hasTill(): bool
    {
        return $this->till->get() !== null;
    }
}
