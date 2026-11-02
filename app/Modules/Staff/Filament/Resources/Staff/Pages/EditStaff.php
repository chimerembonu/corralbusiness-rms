<?php

namespace App\Modules\Staff\Filament\Resources\Staff\Pages;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Resources\Staff\StaffResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditStaff extends EditRecord
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [StaffResource::resetPinAction(), DeleteAction::make()->hidden(fn () => $this->record->is(auth()->user()) || StaffResource::ownerGuarded($this->record))];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->roles->pluck('name')->sort()->values()->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $pin = $this->form->getRawState()['new_pin'] ?? null;
        if (filled($pin)) {
            StaffResource::assertPinFree((string) $pin, $this->record);
            $data['pin'] = (string) $pin;
        }
        if ($this->record->is(auth()->user()) && ! ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['data.is_active' => __('staff::staff.errors.cannot_deactivate_self')]);
        }
        $data['email'] = filled($data['email'] ?? null) ? $data['email'] : null;

        return $data;
    }

    protected function afterSave(): void
    {
        $roles = $this->form->getRawState()['roles'] ?? [];
        if ($this->record->is(auth()->user()) && $this->record->hasRole(Role::Owner->value) && ! in_array(Role::Owner->value, $roles, true)) {
            $roles[] = Role::Owner->value; // an owner cannot demote themself
        }
        $this->record->syncRoles($roles);
    }
}
