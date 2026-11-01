<?php

namespace App\Modules\Staff\Filament\Resources\Staff\Pages;

use App\Modules\Staff\Filament\Resources\Staff\StaffResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('cheatSheet')->label(__('staff::staff.cheat_sheet.button'))->icon(Heroicon::DocumentText)->color('gray')->url(route('staff.cheat-sheet'), shouldOpenInNewTab: true),
            Action::make('cheatSheetPdf')->label(__('staff::staff.cheat_sheet.button_pdf'))->icon(Heroicon::ArrowDownTray)->color('gray')->url(route('staff.cheat-sheet', ['pdf' => 1])),
        ];
    }
}
