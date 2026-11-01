<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Tenancy\StoreTenantResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('pos::layouts.app', ['title' => 'Sign in', 'chrome' => false])]
class PinLogin extends Component
{
    public ?string $error = null;

    public int $lockedFor = 0;

    /** The till this device is; null = not chosen yet. */
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
            $this->error = $e->getMessage();

            return;
        } catch (InvalidPinException $e) {
            $this->error = $e->getMessage();

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $home = $user->can(Permission::OrdersCreate->value) ? route('pos.home') : ($user->can(Permission::DeliveryRide->value) ? route('pos.rider') : route('pos.home'));
        $this->redirect(session()->pull('url.intended', $home));
    }

    private function throttleKey(): string
    {
        return 'till:'.request()->ip();
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
