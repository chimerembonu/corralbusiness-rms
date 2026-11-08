<?php

namespace App\Modules\Staff\Filament\Resources\Staff;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Resources\Staff\Pages\CreateStaff;
use App\Modules\Staff\Filament\Resources\Staff\Pages\EditStaff;
use App\Modules\Staff\Filament\Resources\Staff\Pages\ListStaff;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\PinHasher;
use App\Support\Filament\BranchResource;
use App\Support\Tenancy\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Back office → Staff: profiles, roles, PINs and back-office logins. */
class StaffResource extends BranchResource
{
    /** Staff of this branch, plus people of the whole restaurant (owner, accountant). */
    protected static bool $includeRestaurantWide = true;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'staff';

    public static function getNavigationGroup(): ?string
    {
        return __('staff::staff.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('staff::staff.member');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff::staff.staff');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::StaffManage->value) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    /** @return array<string, string> */
    public static function roleOptions(): array
    {
        return array_combine(Role::values(), array_map(fn (Role $r) => $r->label(), Role::cases()));
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('staff::staff.fields.'.$k);

        return $schema->components([
            Section::make($f('profile'))->columns(2)->schema([
                TextInput::make('name')->label($f('name'))->required()->maxLength(80),
                TextInput::make('phone')->label($f('phone'))->tel()->maxLength(30),
                TextInput::make('job_title')->label($f('job_title'))->maxLength(60),
                DatePicker::make('started_on')->label($f('started_on')),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
                TextInput::make('notes')->label($f('notes'))->maxLength(255),
            ]),
            Section::make($f('access'))->columns(2)->schema([
                CheckboxList::make('roles')->label($f('roles'))->options(self::roleOptions())->required()->columns(2)
                    ->disableOptionWhen(fn (string $value, ?User $record) => in_array($value, self::ownerGivenRoles(), true) && ! auth()->user()->hasRole(Role::Owner->value) && ! $record?->hasRole($value))
                    ->dehydrated(false),
                TextInput::make('new_pin')->label($f('pin'))->helperText($f('pin_help'))
                    ->numeric()->inputMode('numeric')->minLength(4)->maxLength(6)->regex('/^\d{4,6}$/')->dehydrated(false),
                TextInput::make('email')->label($f('email'))->email()->maxLength(120)->unique(ignoreRecord: true),
                TextInput::make('password')->label($f('password'))->helperText($f('password_help'))->password()->revealable()->minLength(8)
                    ->dehydrated(fn ($state) => filled($state)),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('staff::staff.fields.'.$k);

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable()->weight('bold')->description(fn (User $r) => $r->job_title),
                TextColumn::make('roles.name')->label($f('roles'))->badge()->formatStateUsing(fn (string $state) => Role::tryFrom($state)?->label() ?? $state),
                TextColumn::make('phone')->label($f('phone'))->searchable()->placeholder('—'),
                IconColumn::make('has_pin')->label($f('has_pin'))->boolean()->state(fn (User $r) => $r->hasPin()),
                IconColumn::make('is_active')->label($f('is_active'))->boolean(),
                TextColumn::make('last_login_at')->label($f('last_login_at'))->since()->placeholder('—'),
            ])
            ->filters([TernaryFilter::make('is_active')->label($f('is_active'))->default(true)])
            ->recordActions([EditAction::make(), self::resetPinAction(), DeleteAction::make()->hidden(fn (User $r) => $r->is(auth()->user()) || self::ownerGuarded($r))]);
    }

    /**
     * Only the owner may edit, delete or reset the PIN of an owner account (re-review: a manager could take over
     * the owner's PIN), or of another manager's account (E9.6 review M2: a manager who controls a second
     * manager's PIN could give both approvals for a big refund or the day close alone).
     */
    public static function ownerGuarded(User $record): bool
    {
        $me = auth()->user();
        if ($me?->hasRole(Role::Owner->value)) {
            return false;
        }

        return $record->hasRole(Role::Owner->value) || ($record->hasRole(Role::Manager->value) && ! $record->is($me));
    }

    /** Roles only the owner may give: owner and manager (E9.6 review M2). */
    public static function ownerGivenRoles(): array
    {
        return [Role::Owner->value, Role::Manager->value];
    }

    /**
     * Checked when saving, not only on screen: someone who is not the owner cannot give the owner or manager role
     * to anyone (a manager keeps their own).
     *
     * @param  list<string>  $roles
     *
     * @throws ValidationException
     */
    public static function assertRolesAllowed(array $roles, ?User $record = null): void
    {
        if (auth()->user()?->hasRole(Role::Owner->value)) {
            return;
        }
        foreach (array_intersect($roles, self::ownerGivenRoles()) as $role) {
            if (! $record?->hasRole($role)) {
                throw ValidationException::withMessages(['data.roles' => __('staff::staff.errors.owner_gives_role', ['role' => Role::from($role)->label()])]);
            }
        }
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && ! self::ownerGuarded($record);
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && ! self::ownerGuarded($record);
    }

    public static function resetPinAction(): Action
    {
        return Action::make('resetPin')
            ->label(__('staff::staff.actions.reset_pin'))
            ->hidden(fn (User $record) => self::ownerGuarded($record))
            ->icon(Heroicon::Key)
            ->schema([TextInput::make('new_pin')->label(__('staff::staff.actions.new_pin'))->required()->numeric()->inputMode('numeric')->regex('/^\d{4,6}$/')])
            ->action(function (User $record, array $data) {
                abort_if(self::ownerGuarded($record), 403);
                self::assertPinFree($data['new_pin'], $record, 'mountedActions.0.data.new_pin');
                $record->pin = $data['new_pin'];
                $record->save();
                Notification::make()->success()->title(__('staff::staff.actions.pin_reset', ['name' => $record->name]))->send();
            });
    }

    /** @throws ValidationException when another person at this restaurant already uses the PIN */
    public static function assertPinFree(#[\SensitiveParameter] string $pin, ?User $except = null, string $field = 'data.new_pin'): void
    {
        $taken = User::query()->where('pin_lookup', PinHasher::lookup(Tenancy::tenantId(), $pin))
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))->exists();

        if ($taken) {
            throw ValidationException::withMessages([$field => __('staff::staff.errors.pin_in_use')]);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaff::route('/'),
            'create' => CreateStaff::route('/create'),
            'edit' => EditStaff::route('/{record}/edit'),
        ];
    }
}
