<?php

namespace App\Providers\Filament;

use App\Support\Modules;
use App\Support\Tenancy\SetTenantFromUser;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * /admin – back office for the store manager of ONE branch.
 * Resources, pages and widgets are discovered from every enabled module's
 * Filament/ folder (app/Modules/<Name>/Filament/{Resources,Pages,Widgets}).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path(config('rms.admin_path', 'admin'))
            ->login()
            ->brandLogo(fn () => asset(config('rms.brand.logo')))
            ->darkModeBrandLogo(fn () => asset(config('rms.brand.logo_dark')))
            ->brandLogoHeight('2.25rem')
            ->favicon(fn () => asset(config('rms.brand.favicon')))
            ->brandName(config('rms.product_name'))
            ->colors([
                'primary' => Color::Emerald,
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
            ]);

        return self::discoverModules($panel);
    }

    /** Registers every module's Filament resources, pages and widgets on the given panel. */
    public static function discoverModules(Panel $panel): Panel
    {
        foreach (Modules::enabled() as $module) {
            $base = Modules::path($module, 'Filament');
            $namespace = Modules::namespace($module, 'Filament');

            if (is_dir("{$base}/Resources")) {
                $panel->discoverResources(in: "{$base}/Resources", for: "{$namespace}\\Resources");
            }
            if (is_dir("{$base}/Pages")) {
                $panel->discoverPages(in: "{$base}/Pages", for: "{$namespace}\\Pages");
            }
            if (is_dir("{$base}/Widgets")) {
                $panel->discoverWidgets(in: "{$base}/Widgets", for: "{$namespace}\\Widgets");
            }
        }

        return $panel;
    }
}
