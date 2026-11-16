<?php

namespace App\Support\Filament;

use App\Support\Audit\SecurityLog;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A till PIN opens the till screens only (test report 9 Nov 2026, H1). Owners and managers share the
 * till's sign-in, so without this a 4-digit PIN seen over someone's shoulder opened the whole back office
 * with no password and no two-step code. A session started with a PIN is signed out here and sent to the
 * back office's own sign-in page, which asks for the email, password and (when switched on) the code.
 */
class RefuseTillSignIn
{
    public const SESSION_KEY = 'auth.signed_in_with_pin';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->session()->get(self::SESSION_KEY)) {
            return $next($request);
        }

        SecurityLog::tillSessionRefused(Auth::user(), (string) Filament::getCurrentPanel()?->getId());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Notification::make()->warning()->title(__('settings::settings.security.back_office_needs_password'))->persistent()->send();

        if ($request->hasHeader('X-Livewire')) {
            abort(401);
        }

        return redirect()->to(Filament::getCurrentPanel()?->getLoginUrl() ?? url('/'));
    }
}
