<?php

namespace App\Modules\Kitchen\Filament\Pages;

use App\Modules\Kitchen\Models\Route;
use App\Modules\Kitchen\Services\RoutingService;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Staff\Enums\Permission;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Back office → Kitchen routing: which station cooks each category, with per-item overrides. Tenant-wide rules. */
class KitchenRouting extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 61;

    protected string $view = 'kitchen::filament.routing';

    public string $search = '';

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('kitchen::kitchen.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('kitchen::kitchen.routing');
    }

    public function getTitle(): string
    {
        return __('kitchen::kitchen.routing');
    }

    public function setRoute(string $type, string $targetId, string $stationId): void
    {
        app(RoutingService::class)->route($type, $targetId, $stationId === '' ? null : $stationId);
        Notification::make()->success()->title(__('kitchen::kitchen.routing_saved'))->send();
    }

    public function getStations(): Collection
    {
        return app(RoutingService::class)->stations();
    }

    /** @return array<string, string> "type:id" → station id */
    public function getMap(): array
    {
        return app(RoutingService::class)->map();
    }

    public function getCategories(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function getItems(): Collection
    {
        return Item::query()->with('category')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('name')->limit(60)->get();
    }

    public function routeType(string $type): string
    {
        return $type === 'item' ? Route::ITEM : Route::CATEGORY;
    }
}
