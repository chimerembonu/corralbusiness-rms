<?php

namespace App\Modules\Pos\Concerns;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;

/**
 * Manager override on a till screen (CLAUDE.md §3.10). A component calls askManager() with the
 * method to run once a manager has typed a PIN; the PIN keypad is rendered by pos::partials.manager-pin.
 */
trait AsksForManagerPin
{
    /** null when closed, else ['permission', 'method', 'args', 'title', 'error'] */
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
            $manager = $auth->authorize($pin, $this->approval['permission'], 'till:'.request()->ip());
        } catch (PinLockedException|InvalidPinException|ManagerPinRequiredException $e) {
            $this->approval['error'] = $e->getMessage();

            return;
        }

        $approval = $this->approval;
        $this->approval = null;
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
