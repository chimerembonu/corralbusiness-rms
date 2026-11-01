<?php

namespace App\Modules\Inventory\Livewire;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Models\StockTakeLine;
use App\Modules\Inventory\Services\StockTakeService;
use App\Modules\Pos\Concerns\AsksForManagerPin;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Counting on a phone: one line at a time with big buttons, saves on every tap. */
#[Layout('pos::layouts.app', ['title' => 'Stock take'])]
class StockTakeScreen extends Component
{
    use AsksForManagerPin;

    #[Url(as: 'take')]
    public ?string $takeId = null;

    public string $search = '';

    public ?string $notice = null;

    public ?string $error = null;

    /** line id → value typed */
    public array $counts = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->canAny([Permission::InventoryCount->value, Permission::InventoryManage->value]), 403);

        if (! $this->takeId) {
            $this->takeId = app(StockTakeService::class)->open()->first()?->id;
        }
        $this->loadCounts();
    }

    #[Computed]
    public function take(): ?StockTake
    {
        return $this->takeId ? StockTake::query()->open()->find($this->takeId) : null;
    }

    /** @return Collection<int, StockTake> */
    #[Computed]
    public function openTakes(): Collection
    {
        return app(StockTakeService::class)->open();
    }

    /** @return Collection<int, StockTakeLine> */
    #[Computed]
    public function lines(): Collection
    {
        if (! $this->take) {
            return new Collection;
        }

        return $this->take->lines()
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderByRaw('case when counted is null then 0 else 1 end')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function remaining(): int
    {
        return $this->take ? $this->take->lines()->whereNull('counted')->count() : 0;
    }

    /** @return array<int, string> */
    #[Computed]
    public function categories(): array
    {
        return app(StockTakeService::class)->categories();
    }

    public function choose(string $takeId): void
    {
        $this->takeId = $takeId;
        $this->search = '';
        unset($this->take, $this->lines, $this->remaining);
        $this->loadCounts();
    }

    public function start(?string $category = null): void
    {
        $this->error = null;
        try {
            $take = app(StockTakeService::class)->start(auth()->user(), $category);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return;
        }
        unset($this->openTakes);
        $this->choose($take->id);
        $this->notice = __('inventory::inventory.stock_take.actions.started', ['number' => $take->displayNumber(), 'count' => $take->lines()->count()]);
    }

    public function bump(string $lineId, int $delta): void
    {
        $current = (float) ($this->counts[$lineId] ?? 0);
        $this->counts[$lineId] = (string) max(0, $current + $delta);
        $this->save($lineId);
    }

    public function save(string $lineId): void
    {
        $this->error = null;
        if (! $this->take) {
            return;
        }
        $line = StockTakeLine::query()->where('stock_take_id', $this->take->id)->findOrFail($lineId);
        $value = trim((string) ($this->counts[$lineId] ?? ''));

        try {
            app(StockTakeService::class)->count($line, $value === '' ? null : (float) $value, auth()->user());
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();
        }
        unset($this->lines, $this->remaining);
    }

    public function finish(): void
    {
        if (! $this->take) {
            return;
        }
        $this->askManager(Permission::InventoryManage, 'finishApproved', [$this->take->id], __('inventory::inventory.stock_take.actions.complete').' '.$this->take->displayNumber());
    }

    public function finishApproved(User $manager, string $takeId): void
    {
        $take = StockTake::query()->findOrFail($takeId);
        try {
            $counted = $take->lines()->whereNotNull('counted')->count();
            app(StockTakeService::class)->complete($take, $manager);
        } catch (InventoryException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->notice = __('inventory::inventory.stock_take.actions.completed', ['number' => $take->displayNumber(), 'count' => $counted]);
        $this->takeId = null;
        $this->counts = [];
        unset($this->take, $this->openTakes, $this->lines, $this->remaining);
    }

    private function loadCounts(): void
    {
        $this->counts = [];
        foreach ($this->take?->lines ?? [] as $line) {
            $this->counts[$line->id] = $line->counted === null ? '' : (string) (float) $line->counted;
        }
    }

    public function render()
    {
        return view('inventory::livewire.stock-take-screen');
    }
}
