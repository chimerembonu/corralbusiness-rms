<?php

namespace App\Modules\Payments\Filament\Resources\DayCloses\Pages;

use App\Modules\Payments\Filament\Resources\DayCloses\DayCloseResource;
use App\Modules\Payments\Models\DayClose;
use Filament\Resources\Pages\ViewRecord;

class ViewDayClose extends ViewRecord
{
    protected static string $resource = DayCloseResource::class;

    /** The page draws from the record directly; no form to fill (Money objects cannot live in Livewire state). */
    protected function fillForm(): void {}

    protected string $view = 'payments::filament.day-close';

    public function getTitle(): string
    {
        /** @var DayClose $close */
        $close = $this->record;

        return __('payments::payments.day.z_report').' '.$close->displayNumber().' · '.$close->business_date->format('d M Y');
    }

    protected function getHeaderActions(): array
    {
        return [DayCloseResource::reopenAction()];
    }
}
