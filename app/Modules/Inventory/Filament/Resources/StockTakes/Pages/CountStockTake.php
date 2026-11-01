<?php

namespace App\Modules\Inventory\Filament\Resources\StockTakes\Pages;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Filament\Resources\StockTakes\StockTakeResource;
use App\Modules\Inventory\Models\StockTakeLine;
use App\Modules\Inventory\Services\StockTakeService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/** The count sheet: type what you counted next to each line; saves as you go. */
class CountStockTake extends Page
{
    protected static string $resource = StockTakeResource::class;

    use InteractsWithRecord;

    protected string $view = 'inventory::filament.count-stock-take';

    /** line id → counted (string from the inputs) */
    public array $counts = [];

    public string $search = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        foreach ($this->record->lines as $line) {
            $this->counts[$line->id] = $line->counted === null ? '' : (string) (float) $line->counted;
        }
    }

    public function getTitle(): string
    {
        return __('inventory::inventory.stock_take.stock_take').' '.$this->record->displayNumber().($this->record->category ? ' · '.$this->record->category : '');
    }

    public function getSubheading(): ?string
    {
        return $this->record->status->label().' · '.__('inventory::inventory.stock_take.fields.progress').' '.$this->record->lines()->whereNotNull('counted')->count().' / '.$this->record->lines()->count();
    }

    public function lines(): Collection
    {
        return $this->record->lines()->with('countedBy')
            ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%'))->get();
    }

    /** Called from each input on change. */
    public function saveCount(string $lineId): void
    {
        $line = StockTakeLine::query()->where('stock_take_id', $this->record->id)->findOrFail($lineId);
        $value = trim((string) ($this->counts[$lineId] ?? ''));

        try {
            app(StockTakeService::class)->count($line, $value === '' ? null : (float) $value, auth()->user());
        } catch (InventoryException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label(__('inventory::inventory.stock_take.actions.print'))->icon(Heroicon::Printer)->color('gray')
                ->url(fn () => route('inventory.stock-take.print', $this->record), shouldOpenInNewTab: true),
            StockTakeResource::completeAction()->record($this->record)->after(fn () => $this->redirect(StockTakeResource::getUrl('index'))),
            StockTakeResource::cancelAction()->record($this->record)->after(fn () => $this->redirect(StockTakeResource::getUrl('index'))),
        ];
    }
}
