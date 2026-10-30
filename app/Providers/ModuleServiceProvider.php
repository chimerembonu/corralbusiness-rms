<?php

namespace App\Providers;

use App\Support\Modules;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Livewire;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Auto-loads every enabled module under app/Modules/<Name>/ (CLAUDE.md §3.4).
 *
 * For each module it wires up, when the folder/file exists:
 *   <Name>ServiceProvider.php   → registered as a normal service provider (events, bindings)
 *   routes.php                  → web routes (wrapped in the 'web' middleware group)
 *   routes.api.php              → API routes (prefix /api, 'api' middleware group)
 *   migrations/                 → database migrations
 *   views/                      → Blade views under the namespace  <kebab-name>::
 *   lang/                       → translations under the namespace <kebab-name>::
 *   Livewire/**                 → Livewire components registered as  <kebab-name>.<kebab-class-path>
 *
 * Filament resources/pages/widgets inside <Name>/Filament/ are discovered by the panel
 * providers (see App\Providers\Filament\AdminPanelProvider).
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (Modules::enabled() as $module) {
            $provider = Modules::namespace($module, "{$module}ServiceProvider");

            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        foreach (Modules::enabled() as $module) {
            $this->bootModule($module);
        }
    }

    protected function bootModule(string $module): void
    {
        $path = Modules::path($module);
        $viewNamespace = Modules::viewNamespace($module);

        if (! is_dir($path)) {
            return;
        }

        if (is_dir("{$path}/migrations")) {
            $this->loadMigrationsFrom("{$path}/migrations");
        }

        if (is_dir("{$path}/views")) {
            $this->loadViewsFrom("{$path}/views", $viewNamespace);
        }

        if (is_dir("{$path}/lang")) {
            $this->loadTranslationsFrom("{$path}/lang", $viewNamespace);
        }

        if (is_file("{$path}/routes.php")) {
            Route::middleware('web')->group("{$path}/routes.php");
        }

        if (is_file("{$path}/routes.api.php")) {
            Route::middleware('api')->prefix('api')->group("{$path}/routes.api.php");
        }

        if (is_dir("{$path}/Livewire")) {
            $this->registerLivewireComponents($module, "{$path}/Livewire", $viewNamespace);
        }
    }

    /**
     * Registers App\Modules\Pos\Livewire\Orders\OrderScreen as "pos.orders.order-screen".
     */
    protected function registerLivewireComponents(string $module, string $dir, string $prefix): void
    {
        $finder = Finder::create()->files()->in($dir)->name('*.php');

        foreach ($finder as $file) {
            $relative = Str::of($file->getRelativePathname())->beforeLast('.php')->replace('/', '\\');
            $class = Modules::namespace($module, 'Livewire\\'.$relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Component::class)) {
                continue;
            }

            $alias = collect(explode('\\', (string) $relative))
                ->map(fn (string $segment) => Str::kebab($segment))
                ->prepend($prefix)
                ->implode('.');

            Livewire::component($alias, $class);
        }
    }
}
