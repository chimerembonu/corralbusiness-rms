<?php

namespace App\Modules\Staff\Filament\Resources\Staff\Pages;

use App\Modules\Staff\Filament\Resources\Staff\StaffResource;
use App\Support\Tenancy\Tenancy;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    protected static string $resource = StaffResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $pin = $this->form->getRawState()['new_pin'] ?? null;
        if (filled($pin)) {
            StaffResource::assertPinFree((string) $pin);
            $data['pin'] = (string) $pin;
        }
        $data['tenant_id'] = Tenancy::tenantId();
        $data['branch_id'] = Tenancy::branchId();
        $data['email'] = filled($data['email'] ?? null) ? $data['email'] : null;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncRoles($this->form->getRawState()['roles'] ?? []);
    }
}
