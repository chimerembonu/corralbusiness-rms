<?php

namespace App\Modules\Customers\Filament\Resources\Customers;

use App\Modules\Customers\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\ListCustomers;
use App\Modules\Customers\Filament\Resources\Customers\RelationManagers\AccountRelationManager;
use App\Modules\Customers\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Modules\Customers\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Services\CashService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Errors\FriendlyError;
use App\Support\Filament\MoneyInput;
use App\Support\Money\Money;
use App\Support\Tax\TaxIdentificationNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('customers::customers.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('customers::customers.customer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customers::customers.customers');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::CustomersManage->value) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        $f = fn (string $k) => __('customers::customers.fields.'.$k);

        return $schema->components([
            Section::make($f('profile'))->columns(2)->schema([
                TextInput::make('name')->label($f('name'))->required()->maxLength(120),
                TextInput::make('phone')->label($f('phone'))->tel()->maxLength(30)
                    ->rule(fn (?Customer $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        $digits = Customer::digits($value);
                        if ($digits && Customer::query()->where('phone_digits', $digits)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                            $fail(__('customers::customers.errors.phone_taken', ['phone' => $value]));
                        }
                    }),
                TextInput::make('email')->label($f('email'))->email()->maxLength(120),
                TextInput::make('tin')->label($f('tin'))->maxLength(20)->helperText($f('tin_help'))
                    ->dehydrateStateUsing(fn (?string $state) => TaxIdentificationNumber::normalise($state) ?: null)
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if (TaxIdentificationNumber::normalise($value) === false) {
                            $fail(__('orders::orders.errors.bad_tin'));
                        }
                    }),
                TagsInput::make('tags')->label($f('tags')),
                TextInput::make('notes')->label($f('notes'))->maxLength(255)->columnSpanFull(),
                Toggle::make('is_active')->label($f('is_active'))->default(true)->inline(false),
            ]),
            Section::make($f('account'))->columns(2)->schema([
                Toggle::make('allow_account')->label($f('allow_account'))->inline(false)->live(),
                MoneyInput::make('credit_limit')->label($f('credit_limit'))->helperText($f('credit_limit_help'))->visible(fn (Get $get) => (bool) $get('allow_account')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $f = fn (string $k) => __('customers::customers.fields.'.$k);

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label($f('name'))->searchable()->sortable()->weight('bold')->description(fn (Customer $r) => $r->notes),
                TextColumn::make('phone')->label($f('phone'))->searchable(query: fn (Builder $q, string $search) => $q->where('phone', 'like', "%{$search}%")->orWhere('phone_digits', 'like', '%'.preg_replace('/\D+/', '', $search).'%'))->placeholder('—'),
                TextColumn::make('orders_count')->label($f('orders_count'))->alignRight()->sortable(),
                TextColumn::make('total_spent')->label($f('total_spent'))->formatStateUsing(fn ($state) => MoneyInput::format($state))->alignRight()->sortable(),
                TextColumn::make('last_order_at')->label($f('last_order_at'))->since()->placeholder('—')->sortable(),
                TextColumn::make('account_balance')->label($f('account_balance'))->formatStateUsing(fn (Customer $r) => $r->allow_account ? money($r->balance()) : '—')->alignRight()->sortable()
                    ->color(fn (Customer $r) => $r->owes() ? 'warning' : null)->weight(fn (Customer $r) => $r->owes() ? 'bold' : null),
                TextColumn::make('points_balance')->label($f('points_balance'))->alignRight()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('allow_account')->label($f('allow_account'))->boolean()->toggleable(),
            ])
            ->filters([
                Filter::make('owes')->label(__('customers::customers.account.debtors'))->query(fn (Builder $q) => $q->where('account_balance', '>', 0)),
                Filter::make('account')->label($f('allow_account'))->query(fn (Builder $q) => $q->where('allow_account', true)),
            ])
            ->recordActions([self::receiveAction(), EditAction::make(), DeleteAction::make()]);
    }

    public static function receiveAction(): Action
    {
        $a = fn (string $k) => __('customers::customers.account.'.$k);

        return Action::make('receive')->label($a('receive'))->icon(Heroicon::Banknotes)->color('success')
            ->visible(fn (Customer $record) => $record->allow_account)
            ->modalDescription($a('receive_help'))
            ->fillForm(fn (Customer $record) => ['amount' => $record->owes() ? $record->balance()->toDecimal() : null, 'method' => 'cash'])
            ->schema([
                MoneyInput::make('amount')->label($a('amount'))->required(),
                Select::make('method')->label($a('method'))->options([PaymentMethod::Cash->value => PaymentMethod::Cash->label(), PaymentMethod::MobileMoney->value => PaymentMethod::MobileMoney->label(), PaymentMethod::Card->value => PaymentMethod::Card->label()])->required()->live()->native(false),
                Select::make('provider')->label(__('payments::payments.errors.provider_required'))->options(array_combine(array_map(fn (MomoProvider $p) => $p->value, MomoProvider::cases()), array_map(fn (MomoProvider $p) => $p->label(), MomoProvider::cases())))->visible(fn (Get $get) => $get('method') === 'momo')->native(false),
                TextInput::make('reference')->label($a('reference'))->maxLength(100)->visible(fn (Get $get) => $get('method') !== 'cash'),
                // Cash goes into an open till's drawer, so that till's cash-up counts it (security review D3).
                Select::make('drawer')->label($a('drawer'))->helperText($a('drawer_help'))->native(false)
                    ->options(fn () => app(CashService::class)->openSessions()->mapWithKeys(fn (CashierSession $s) => [$s->id => ($s->till?->name ?? __('customers::customers.account.no_till')).' · '.$s->user?->name])->all())
                    ->default(fn () => app(CashService::class)->current(auth()->user())?->id)
                    ->required(fn (Get $get) => $get('method') === 'cash')
                    ->visible(fn (Get $get) => $get('method') === 'cash'),
            ])
            ->action(function (Customer $record, array $data) {
                try {
                    $drawer = ($data['method'] === 'cash' && filled($data['drawer'] ?? null)) ? CashierSession::query()->forBranch()->open()->find($data['drawer']) : null;
                    app(AccountService::class)->receivePayment($record, PaymentMethod::from($data['method']), Money::of((int) $data['amount']), auth()->user(), $data['reference'] ?? null, isset($data['provider']) ? MomoProvider::from($data['provider']) : null, drawer: $drawer);
                    Notification::make()->success()->title(__('customers::customers.account.received', ['amount' => money((int) $data['amount']), 'name' => $record->name, 'balance' => money($record->fresh()->balance())]))->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(FriendlyError::message($e))->send();
                }
            });
    }

    public static function adjustAction(): Action
    {
        $a = fn (string $k) => __('customers::customers.account.'.$k);

        return Action::make('adjust')->label($a('adjust'))->icon(Heroicon::AdjustmentsHorizontal)->color('warning')
            ->visible(fn (Customer $record) => $record->allow_account && auth()->user()->can(Permission::OrdersRefund->value))
            ->modalDescription($a('adjust_help'))
            ->schema([
                TextInput::make('amount')->label($a('amount'))->numeric()->required()->prefix(config('rms.currency_symbol')),
                TextInput::make('reason')->label($a('reason'))->required()->maxLength(255),
            ])
            ->action(function (Customer $record, array $data) {
                try {
                    app(AccountService::class)->adjust($record, Money::fromDecimal((string) $data['amount']), auth()->user(), $data['reason']);
                    Notification::make()->success()->title(__('customers::customers.account.adjusted', ['balance' => money($record->fresh()->balance())]))->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title(FriendlyError::message($e))->send();
                }
            });
    }

    public static function getRelations(): array
    {
        return [AddressesRelationManager::class, OrdersRelationManager::class, AccountRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
