<?php

namespace App\Support\Filament;

use App\Modules\Orders\Support\TillSettings;
use App\Support\Http\UserActivity;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shorter back-office sessions (Complete Edition E9.2): if nobody has used the back office for the set
 * minutes (Till settings → Security, default 30), the next request signs out and returns to the login.
 * Screens refreshing themselves do not count as use.
 */
class SignOutIdleBackOffice
{
    public const LAST_ACTIVITY = 'back_office.last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }
        $minutes = app(TillSettings::class)->backOfficeIdleMinutes();
        $last = (int) $request->session()->get(self::LAST_ACTIVITY, 0);

        if ($minutes > 0 && $last > 0 && now()->getTimestamp() - $last > $minutes * 60) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Notification::make()->warning()->title(__('settings::settings.security.signed_out_idle', ['minutes' => $minutes]))->persistent()->send();

            $login = Filament::getCurrentPanel()?->getLoginUrl() ?? url('/');

            return $request->hasHeader('X-Livewire') ? abort(401) : redirect()->to($login);
        }
        if (UserActivity::isPerson($request)) {
            $request->session()->put(self::LAST_ACTIVITY, now()->getTimestamp());
        }

        return $next($request);
    }
}
