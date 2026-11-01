<?php

namespace App\Modules\Customers\Filament\Resources\Customers\Pages;

use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /** Running totals are Money objects that no form field edits; keep them out of the form state. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['total_spent'], $data['account_balance'], $data['points_balance'], $data['orders_count'], $data['last_order_at']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            CustomerResource::receiveAction(),
            CustomerResource::adjustAction(),
            Action::make('statement')->label(__('customers::customers.fields.statement'))->icon(Heroicon::Printer)->color('gray')
                ->visible(fn () => $this->record->allow_account)
                ->url(fn () => route('customers.statement', $this->record), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
