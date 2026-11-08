<?php

namespace App\Modules\Settings\Filament\Widgets;

use App\Modules\Menu\Filament\Resources\Items\ItemResource;
use App\Modules\Menu\Models\Item;
use App\Modules\Orders\Filament\Pages\FloorPlan;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Filament\Resources\Printers\PrinterResource;
use App\Modules\Printing\Models\Printer;
use App\Modules\Settings\Filament\Pages\BackupsPage;
use App\Modules\Settings\Filament\Pages\ImportPage;
use App\Modules\Settings\Filament\Pages\LicencePage;
use App\Modules\Settings\Services\BackupService;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Resources\Staff\StaffResource;
use App\Modules\Staff\Models\User;
use App\Support\Licence\LicenceService;
use Filament\Widgets\Widget;
use Throwable;

/**
 * Back-office home page card for a new restaurant: what is left to do after the setup wizard, each with a
 * link (install rehearsal, 8 Nov 2026: a new owner landed on an empty dashboard with no next step). Ticks
 * itself off from what exists; hides when the needed steps are done or someone hides it.
 */
class GettingStartedWidget extends Widget
{
    public const HIDDEN_SETTING = 'setup.checklist_hidden';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'settings::filament.getting-started-widget';

    public static function canView(): bool
    {
        if (! (auth()->user()?->can(Permission::SettingsManage->value) ?? false)) {
            return false;
        }
        if (app(SettingsService::class)->get(self::HIDDEN_SETTING)) {
            return false;
        }

        return collect((new self)->steps())->contains(fn (array $s) => ! $s['done'] && ! $s['optional']);
    }

    /** @return array<int, array{key: string, done: bool, optional: bool, url: ?string, extra: ?string}> */
    public function steps(): array
    {
        $safe = function (callable $check): bool {
            try {
                return (bool) $check();
            } catch (Throwable) {
                return false;
            }
        };
        $url = function (string $class, ...$args): ?string {
            try {
                return $class::getUrl(...$args);
            } catch (Throwable) {
                return null;
            }
        };

        return [
            ['key' => 'menu', 'done' => $safe(fn () => Item::query()->exists()), 'optional' => false, 'url' => $url(ItemResource::class), 'extra' => $url(ImportPage::class)],
            ['key' => 'staff', 'done' => $safe(fn () => User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', Role::Owner->value))->exists()), 'optional' => false, 'url' => $url(StaffResource::class), 'extra' => $url(ImportPage::class)],
            ['key' => 'printers', 'done' => $safe(fn () => Printer::query()->exists()), 'optional' => false, 'url' => $url(PrinterResource::class), 'extra' => null],
            ['key' => 'tables', 'done' => $safe(fn () => DiningTable::query()->exists()), 'optional' => true, 'url' => $url(FloorPlan::class), 'extra' => null],
            ['key' => 'practice', 'done' => $safe(fn () => Order::query()->exists()), 'optional' => false, 'url' => route('pos.home'), 'extra' => null],
            ['key' => 'backup', 'done' => $safe(fn () => app(BackupService::class)->list() !== []), 'optional' => false, 'url' => $url(BackupsPage::class), 'extra' => null],
            ['key' => 'licence', 'done' => $safe(fn () => app(LicenceService::class)->current() !== null), 'optional' => true, 'url' => $url(LicencePage::class), 'extra' => null],
        ];
    }

    public bool $hidden = false;

    public function hide(): void
    {
        abort_unless(auth()->user()?->can(Permission::SettingsManage->value) ?? false, 403);
        app(SettingsService::class)->set(self::HIDDEN_SETTING, true, tenantWide: true);
        $this->hidden = true;
    }
}
