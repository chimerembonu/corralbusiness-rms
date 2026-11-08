<?php

namespace App\Modules\Settings\Http;

use App\Modules\Settings\Services\SetupService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * On a fresh install (no restaurant yet) every screen opens the setup wizard instead (E1.3), so the owner
 * only has to open the server's address in a browser. Once set up this costs nothing (remembered in cache).
 */
class RedirectToSetup
{
    private const SCREENS = ['/', 'pos', 'pos/*', 'admin', 'admin/*', 'hq', 'hq/*', 'kitchen', 'kitchen/*', 'expo', 'expo/*', 'clock'];

    public function __construct(private readonly SetupService $setup) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire') && $request->is(...self::SCREENS)) {
            try {
                $open = $this->setup->isOpen();
            } catch (Throwable) {
                $open = false; // database not reachable: let the start page explain
            }
            if ($open) {
                return redirect()->route('setup');
            }
        }

        return $next($request);
    }
}
