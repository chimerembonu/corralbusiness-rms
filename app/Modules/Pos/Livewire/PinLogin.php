<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Tenancy\StoreTenantResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('pos::layouts.app', ['title' => 'Sign in', 'chrome' => false])]
class PinLogin extends Component
{
    public ?string $error = null;

    public int $lockedFor = 0;

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectRoute('pos.home');
        }

        $this->error = session('error');
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

        $this->redirect(session()->pull('url.intended', route('pos.home')));
    }

    private function throttleKey(): string
    {
        return 'till:'.request()->ip();
    }

    public function render()
    {
        return view('pos::livewire.pin-login');
    }
}
