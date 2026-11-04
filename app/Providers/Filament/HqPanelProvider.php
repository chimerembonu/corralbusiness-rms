<?php

namespace App\Providers\Filament;

use App\Modules\Staff\Filament\Auth\Login;
use App\Support\Licence\EnforceLicence;
use App\Support\Tenancy\SetTenantFromUser;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * /hq – the tenant owner's panel across ALL branches.
 * Placeholder in Phase 0; cross-branch resources are added in Phase 6.
 */
class HqPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('hq')
            ->path(config('rms.hq_path', 'hq'))
            ->login(Login::class) // logs throttled sign-ins (compliance assessment)
            // Button presses that fail show these instead of a technical error box (same words as the till).
            ->registerErrorNotification(fn () => __('errors.pages.500.title'), fn () => __('errors.action.failed'))
            ->registerErrorNotification(fn () => __('errors.pages.403.title'), fn () => __('errors.action.forbidden'), 403)
            ->registerErrorNotification(fn () => __('errors.pages.404.title'), fn () => __('errors.action.gone'), 404)
            ->registerErrorNotification(fn () => __('errors.pages.419.title'), fn () => __('errors.pages.419.body'), 419)
            ->brandLogo(fn () => asset(config('rms.brand.logo')))
            ->darkModeBrandLogo(fn () => asset(config('rms.brand.logo_dark')))
            ->brandLogoHeight('2.25rem')
            ->favicon(fn () => asset(config('rms.brand.favicon')))
            ->brandName(config('rms.product_name').' · HQ')
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetTenantFromUser::class,
                EnforceLicence::class, // reports-only once a licence has ended (E1.2)
            ])
            // Licence warning (ending soon, grace period, reports-only) for owner and managers.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('settings::filament.licence-banner'));
    }
}
