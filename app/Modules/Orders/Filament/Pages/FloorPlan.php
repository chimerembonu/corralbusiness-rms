<?php

namespace App\Modules\Orders\Filament\Pages;

use App\Modules\Orders\Filament\Resources\DiningTables\DiningTableResource;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Services\TableService;
use App\Modules\Staff\Enums\Permission;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Drag-to-arrange floor plan editor (Phase 1.1). Positions save on every drop. */
class FloorPlan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 30;

    protected string $view = 'orders::filament.floor-plan';

    public ?string $sectionId = null;

    /** Who may open this in the back office (security review M6): checked for the menu entry and every page of it. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('orders::orders.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('orders::orders.floor_plan.title');
    }

    public function getTitle(): string
    {
        return __('orders::orders.floor_plan.title');
    }

    public function selectSection(?string $sectionId): void
    {
        $this->sectionId = $sectionId ?: null;
    }

    /** Called by the drag script when a table is dropped. */
    public function moveTable(string $tableId, int $x, int $y): void
    {
        app(TableService::class)->arrange([['id' => $tableId, 'x' => $x, 'y' => $y]]);
    }

    public function addTableAction(): Action
    {
        return Action::make('addTable')
            ->label(__('orders::orders.floor_plan.add_table'))
            ->icon(Heroicon::Plus)
            ->schema(DiningTableResource::fields())
            ->fillForm(fn () => ['section_id' => $this->sectionId])
            ->action(function (array $data) {
                $table = DiningTable::create($data);
                $this->sectionId = $table->section_id;
                Notification::make()->success()->title(__('orders::orders.floor_plan.table_added', ['name' => $table->name]))->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addTableAction(),
            Action::make('tables')->label(__('orders::orders.tables'))->url(DiningTableResource::getUrl())->color('gray'),
        ];
    }

    /** @return Collection<int, array{section: mixed, tables: Collection<int, DiningTable>}> */
    public function getPlan(): Collection
    {
        return app(TableService::class)->floorPlan();
    }

    public function getCurrentGroup(): ?array
    {
        $plan = $this->getPlan();

        return $plan->first(fn (array $g) => ($g['section']?->id ?? '') === ($this->sectionId ?? '')) ?? $plan->first();
    }

    public function editUrl(DiningTable $table): string
    {
        return DiningTableResource::getUrl('edit', ['record' => $table]);
    }
}
