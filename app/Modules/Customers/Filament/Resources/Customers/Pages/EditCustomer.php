<?php

namespace App\Modules\Customers\Filament\Resources\Customers\Pages;

use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use App\Modules\Customers\Services\CustomerPrivacyService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
            Action::make('exportData')->label(__('customers::customers.privacy.export_button'))->icon(Heroicon::ArrowDownTray)->color('gray')
                ->visible(fn () => CustomerPrivacyService::allowed(auth()->user()) && ! $this->record->anonymised_at)
                ->action(function (CustomerPrivacyService $privacy) {
                    abort_unless(CustomerPrivacyService::allowed(auth()->user()), 403);
                    activity('customers')->performedOn($this->record)->causedBy(auth()->user())->log('customer_data_exported');

                    return $privacy->export($this->record);
                }),
            Action::make('anonymise')->label(__('customers::customers.privacy.anonymise_button'))->icon(Heroicon::UserMinus)->color('danger')
                ->visible(fn () => CustomerPrivacyService::allowed(auth()->user()) && ! $this->record->anonymised_at)
                ->requiresConfirmation()
                ->modalHeading(fn () => __('customers::customers.privacy.anonymise_heading', ['name' => $this->record->name]))
                ->modalDescription(__('customers::customers.privacy.anonymise_description'))
                ->schema([Textarea::make('reason')->label(__('customers::customers.privacy.reason'))->required()->maxLength(500)])
                ->action(function (array $data, CustomerPrivacyService $privacy, Action $action) {
                    abort_unless(CustomerPrivacyService::allowed(auth()->user()), 403);
                    try {
                        $privacy->anonymise($this->record, auth()->user(), (string) $data['reason']);
                    } catch (CustomerException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }
                    Notification::make()->success()->title(__('customers::customers.privacy.anonymised'))->send();
                    $this->redirect(CustomerResource::getUrl('index'));
                }),
            DeleteAction::make(),
        ];
    }
}
