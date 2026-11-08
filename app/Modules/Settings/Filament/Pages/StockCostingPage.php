<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Inventory\Enums\CostMethod;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Services\CostService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Back office → Settings → Stock costing: how ingredients are valued (owner's decision, 8 Nov 2026). The owner
 * and the accountant choose; managers can look. Chosen first in the setup wizard; every change needs a reason
 * and is kept in the history.
 */
class StockCostingPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'stock-costing';

    protected string $view = 'settings::filament.stock-costing';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can(Permission::SettingsManage->value) || $user->hasRole(Role::Accountant->value));
    }

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.costing.title');
    }

    public function getTitle(): string
    {
        return __('settings::settings.costing.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.costing.help');
    }

    public function canChange(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return CostService::canChooseMethod($user);
    }

    public function mount(CostService $costs): void
    {
        $this->form->fill(['method' => $costs->method()->value, 'reason' => '']);
    }

    public function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __('settings::settings.costing.'.$key);

        return $schema->components([
            Section::make($t('method'))->description($this->canChange() ? $t('applies') : $t('who'))->schema([
                Radio::make('method')->hiddenLabel()->required()
                    ->options(CostMethod::options())
                    ->descriptions(collect(CostMethod::cases())->mapWithKeys(fn (CostMethod $m) => [$m->value => $m->about()])->all())
                    ->disabled(! $this->canChange()),
                Textarea::make('reason')->label($t('reason'))->placeholder($t('reason_placeholder'))->rows(2)->maxLength(500)
                    ->visible($this->canChange()),
            ]),
        ])->statePath('data');
    }

    public function save(CostService $costs): void
    {
        abort_unless($this->canChange(), 403);
        $data = $this->form->getState();
        $method = CostMethod::from($data['method']);
        if ($method === $costs->method()) {
            Notification::make()->info()->title(__('settings::settings.costing.unchanged'))->send();

            return;
        }
        try {
            $costs->setMethod($method, auth()->user(), $data['reason'] ?? null);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['data.reason' => $e->getMessage()]);
        }
        $this->form->fill(['method' => $method->value, 'reason' => '']);
        Notification::make()->success()->title(__('settings::settings.costing.saved', ['method' => $method->label()]))->send();
    }

    /** @return Collection<int, Activity> the choices made so far, newest first */
    public function changes(): Collection
    {
        return Activity::query()->with('causer')->where('event', 'cost_method_changed')->latest('id')->limit(20)->get();
    }
}
