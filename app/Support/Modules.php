<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Registry of the application's modules (see config/modules.php).
 *
 * Usage:
 *   Modules::enabled()            → ['Menu', 'Orders', …]
 *   Modules::path('Menu')         → /…/app/Modules/Menu
 *   Modules::namespace('Menu')    → App\Modules\Menu
 *   Modules::viewNamespace('Menu') → 'menu'   (use as  view('menu::items.index'))
 */
final class Modules
{
    /** @return array<int, string> Names of every module declared in config, in order. */
    public static function all(): array
    {
        return array_keys(config('modules.modules', []));
    }

    /** @return array<int, string> Names of modules that are switched on (core modules are always on). */
    public static function enabled(): array
    {
        return array_keys(array_filter(
            config('modules.modules', []),
            fn (array $module) => ($module['core'] ?? false) || ($module['enabled'] ?? false),
        ));
    }

    public static function isEnabled(string $name): bool
    {
        return in_array($name, self::enabled(), true);
    }

    public static function isCore(string $name): bool
    {
        return (bool) (config("modules.modules.{$name}.core") ?? false);
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, config('modules.modules', []));
    }

    public static function path(string $name, string $append = ''): string
    {
        $base = rtrim(config('modules.path', app_path('Modules')), '/').'/'.$name;

        return $append === '' ? $base : $base.'/'.ltrim($append, '/');
    }

    public static function namespace(string $name, string $append = ''): string
    {
        $base = rtrim(config('modules.namespace', 'App\\Modules'), '\\').'\\'.$name;

        return $append === '' ? $base : $base.'\\'.ltrim($append, '\\');
    }

    /** Blade view namespace, e.g. view('pos::screen'). */
    public static function viewNamespace(string $name): string
    {
        return Str::kebab($name);
    }
}
