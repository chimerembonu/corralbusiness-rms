<?php

namespace App\Modules\Menu\Observers;

use App\Modules\Menu\Services\MenuService;

/** Any change to the menu clears the cached till catalog. */
class MenuCacheBuster
{
    public function saved(): void
    {
        MenuService::forgetCatalog();
    }

    public function deleted(): void
    {
        MenuService::forgetCatalog();
    }

    public function restored(): void
    {
        MenuService::forgetCatalog();
    }
}
