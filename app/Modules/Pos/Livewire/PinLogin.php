<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Tenancy\StoreTenantResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('pos::layouts.app', ['title' => 'Sign in', 'chrome' => false])]
class PinLogin extends Component
{
    use ShowsFriendlyErrors;

    public ?string $error = null;

    public int $lockedFor = 0;

    /** The till this device is; null = not chosen yet. Locked: the browser must not be able to change it (it keys the PIN lockout). */
    #[Locked]
    public ?string $tillId = null;

    public function mount(CurrentTill $current, StoreTenantResolver $store): void
    {
        if (Auth::check()) {
            $this->redirectRoute('pos.home');
        }

        $this->error = session('error');

        if (request()->boolean('change-till')) {
            $current->forget();
        } elseif ($tenant = $store->tenant()) {
            Tenancy::set($tenant, $store->branch());
            $this->tillId = $current->get()?->id;
        }
    }

    public function chooseTill(string $tillId, CurrentTill $current, StoreTenantResolver $store): void
    {
        if ($tenant = $store->tenant()) {
            Tenancy::set($tenant, $store->branch());
        }
        $till = Till::query()->active()->find($tillId);
        if ($till) {
            $current->remember($till);
            $this->tillId = $till->id;
        }
    }

    public function changeTill(CurrentTill $current): void
    {
        $current->forget();
        $this->tillId = null;
    }

    public function login(string $pin, PinAuthenticator $auth, StoreTenantResolver $store): void
    {
        $this->error = null;
        $this->lockedFor = 0;
        $tenant = $store->tenant();

        if (! $tenant) {
            $this->error = __('pos::pos.login.no_store');

            return;
        }

        try {
            $user = $auth->attempt($pin, $this->throttleKey(), $tenant);
        } catch (PinLockedException $e) {
            $this->lockedFor = $e->retryAfterSeconds;
            $this->error = FriendlyError::message($e);

            return;
        } catch (InvalidPinException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }

        $till = Till::query()->active()->find($this->tillId);
        if ($till && $user->branch_id !== null && $till->branch_id !== $user->branch_id) {
            $this->error = __('pos::pos.login.wrong_branch'); // security review M7

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $home = match (true) {
            $user->can(Permission::OrdersCreate->value) => route('pos.home'),
            $user->can(Permission::DeliveryRide->value) => route('pos.rider'),
            $user->can(Permission::KitchenView->value) => route('kitchen.display'),
            default => route('pos.home'),
        };
        $this->redirect(session()->pull('url.intended', $home));
    }

    /**
     * Lockouts count per till (not per network address, which every till behind one router shares).
     * $tillId is #[Locked] and the till cookie is encrypted, so neither can be rotated by the browser
     * to dodge the lockout.
     */
    private function throttleKey(): string
    {
        return 'till:'.($this->tillId ?? app(CurrentTill::class)->id() ?? request()->ip());
    }

    public function render(StoreTenantResolver $store)
    {
        $tills = collect();
        if ($tenant = $store->tenant()) {
            Tenancy::set($tenant, $store->branch());
            $tills = Till::query()->active()->orderBy('name')->get();
        }

        return view('pos::livewire.pin-login', [
            'tills' => $tills,
            'till' => $this->tillId ? $tills->firstWhere('id', $this->tillId) : null,
        ]);
    }
}
