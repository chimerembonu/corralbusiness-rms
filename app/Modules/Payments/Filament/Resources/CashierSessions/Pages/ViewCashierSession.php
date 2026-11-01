<?php

namespace App\Modules\Payments\Filament\Resources\CashierSessions\Pages;

use App\Modules\Payments\Filament\Resources\CashierSessions\CashierSessionResource;
use App\Modules\Payments\Models\CashierSession;
use Filament\Resources\Pages\ViewRecord;

/** The cash-up slip, rendered with the same template the till prints. */
class ViewCashierSession extends ViewRecord
{
    protected static string $resource = CashierSessionResource::class;

    /** The page draws from the record directly; no form to fill (Money objects cannot live in Livewire state). */
    protected function fillForm(): void {}

    protected string $view = 'payments::filament.cashier-session';

    public function getTitle(): string
    {
        /** @var CashierSession $session */
        $session = $this->record;

        return __('payments::payments.cash.session').' '.$session->displayNumber();
    }

    public function getSession(): CashierSession
    {
        /** @var CashierSession $session */
        $session = $this->record;
        $session->load(['till', 'user', 'closedBy', 'approvedBy', 'movements.user']);

        return $session;
    }
}
