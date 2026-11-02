<?php

namespace App\Modules\Pos\Concerns;

use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Tenancy\Tenancy;
use Livewire\Attributes\Locked;

/**
 * Manager override on a till screen (CLAUDE.md §3.10). A component calls askManager() with the
 * method to run once a manager has typed a PIN; the PIN keypad is rendered by pos::partials.manager-pin.
 *
 * Security: the "…Approved" methods must be protected (never callable from the browser) and
 * $approval is locked, so the only way to reach them is approve() with a valid manager PIN.
 */
trait AsksForManagerPin
{
    /** null when closed, else ['permission', 'method', 'args', 'title', 'error'] */
    #[Locked]
    public ?array $approval = null;

    protected function askManager(Permission $permission, string $method, array $args, string $title): void
    {
        $this->approval = ['permission' => $permission->value, 'method' => $method, 'args' => $args, 'title' => $title, 'error' => null];
    }

    public function cancelApproval(): void
    {
        $this->approval = null;
    }

    /** Called by the keypad with the manager's PIN. */
    public function approve(string $pin, PinAuthenticator $auth): void
    {
        if (! $this->approval) {
            return;
        }

        try {
            $manager = $auth->authorize($pin, $this->approval['permission'], app(CurrentTill::class)->throttleKey(), null, Tenancy::branchId());
        } catch (PinLockedException|InvalidPinException|ManagerPinRequiredException $e) {
            $this->approval['error'] = $e->getMessage();

            return;
        }

        $approval = $this->approval;
        $this->approval = null;

        if (! str_ends_with($approval['method'], 'Approved') || ! method_exists($this, $approval['method'])) {
            abort(400);
        }

        $this->{$approval['method']}($manager, ...$approval['args']);
    }

    /** Run $callback; when it needs a manager, open the PIN keypad to retry with their approval. */
    protected function withManagerFallback(callable $callback, Permission $permission, string $method, array $args, string $title): void
    {
        try {
            $callback();
        } catch (ManagerPinRequiredException) {
            $this->askManager($permission, $method, $args, $title);
        }
    }

    protected function currentUser(): User
    {
        return auth()->user();
    }
}
