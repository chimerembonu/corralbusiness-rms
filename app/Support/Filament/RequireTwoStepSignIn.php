<?php

namespace App\Support\Filament;

use App\Modules\Staff\Enums\Role;
use App\Support\Features\FeatureService;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step sign-in for owners and managers (Complete Edition E9.2). With "Two-step sign-in" switched on,
 * an owner or manager who has not set it up yet can only open their profile page (where it is set up) or
 * sign out; everything else sends them there. Once set up, the back office asks for the authenticator
 * app's code at every sign-in (Filament's own challenge).
 */
class RequireTwoStepSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! self::required($user) || $user->hasTwoStepSignIn()) {
            return $next($request);
        }
        $panel = Filament::getCurrentPanel();
        $profile = $panel?->getProfileUrl();
        $allowed = $request->routeIs('filament.*.auth.profile', 'filament.*.auth.logout');
        if ($allowed || ! $profile) {
            return $next($request);
        }
        if ($request->hasHeader('X-Livewire')) {
            abort(403, __('settings::settings.security.two_step_needed'));
        }
        Notification::make()->warning()->title(__('settings::settings.security.two_step_needed'))->persistent()->send();

        return redirect()->to($profile);
    }

    /** Must this person use two-step sign-in? Owners and managers, while the feature is switched on. */
    public static function required(mixed $user): bool
    {
        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole([Role::Owner->value, Role::Manager->value])
            && app(FeatureService::class)->isOn('two_step_sign_in');
    }
}
