<?php

namespace App\Modules\Printing\Filament\Resources\Printers\Pages;

use App\Modules\Printing\Filament\Resources\Printers\PrinterResource;
use App\Modules\Printing\Services\PrintService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPrinter extends EditRecord
{
    protected static string $resource = PrinterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testPrint')->label(__('printing::printing.actions.test_print'))->icon(Heroicon::OutlinedPrinter)->color('success')
                ->action(function () {
                    app(PrintService::class)->testPrint($this->getRecord());
                    Notification::make()->title(__('printing::printing.actions.test_queued', ['printer' => $this->getRecord()->name]))->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
