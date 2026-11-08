<?php

namespace App\Support\Licence;

use App\Modules\Settings\Filament\Pages\LicencePage;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back office and head office in reports-only mode (licence ended and grace period over): only the pages in
 * config licence.read_only_pages open (reports, day closes, backups, the Licence page); everything else goes
 * to the Licence page. Also runs on every button press (Livewire), so a page left open cannot still save.
 */
class EnforceLicence
{
    public function __construct(private readonly LicenceService $licence) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->licence->isReadOnly() || Str::is(config('licence.read_only_pages', []), (string) $request->route()?->getName())) {
            return $next($request);
        }

        if ($request->hasHeader('X-Livewire')) {
            abort(403, __('settings::settings.licence.read_only_action'));
        }

        Notification::make()->warning()->title(__('settings::settings.licence.banner.read_only'))->persistent()->send();

        return redirect()->to(LicencePage::getUrl(panel: 'admin'));
    }
}
