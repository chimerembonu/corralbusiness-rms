<?php

namespace App\Modules\Customers\Livewire;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Staff\Enums\Permission;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Money\Money;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/** Till screen: find a customer, see their history and balance, take an account payment, add a new one. */
#[Layout('pos::layouts.app', ['title' => 'Customers'])]
class CustomersScreen extends Component
{
    use ShowsFriendlyErrors;

    #[Url]
    public string $search = '';

    #[Url(as: 'customer')]
    public ?string $customerId = null;

    /** null | receive | new */
    public ?string $modal = null;

    public string $method = 'cash';

    public ?string $provider = null;

    public string $amount = '';

    public string $reference = '';

    public array $newCustomer = ['name' => '', 'phone' => '', 'address' => ''];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::CustomersManage->value), 403);
    }

    #[Computed]
    public function matches()
    {
        return app(CustomerService::class)->search($this->search, 20);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerId ? Customer::query()->with('addresses')->find($this->customerId) : null;
    }

    #[Computed]
    public function history()
    {
        return $this->customer ? app(CustomerService::class)->history($this->customer, 10) : collect();
    }

    #[Computed]
    public function statement()
    {
        return $this->customer ? app(AccountService::class)->statement($this->customer)->reverse()->take(10) : collect();
    }

    public function select(?string $customerId): void
    {
        $this->customerId = $customerId;
        $this->modal = null;
        $this->notice = $this->error = null;
        unset($this->customer, $this->history, $this->statement);
    }

    public function openModal(string $name): void
    {
        $this->modal = $name;
        $this->error = null;
        $this->method = 'cash';
        $this->provider = null;
        $this->reference = '';
        $this->amount = $name === 'receive' && $this->customer?->owes() ? $this->customer->balance()->toDecimal() : '';
        $this->newCustomer = ['name' => '', 'phone' => '', 'address' => ''];
    }

    public function closeModal(): void
    {
        $this->modal = null;
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function receive(): void
    {
        $this->error = null;
        if (! $this->customer) {
            return;
        }
        try {
            $payment = app(AccountService::class)->receivePayment(
                $this->customer,
                PaymentMethod::from($this->method),
                Money::fromDecimal($this->amount === '' ? '0' : $this->amount),
                auth()->user(),
                $this->reference,
                $this->provider ? MomoProvider::from($this->provider) : null,
            );
        } catch (RuntimeException|\ValueError $e) {
            $this->error = $e instanceof \ValueError ? __('payments::payments.errors.provider_required') : FriendlyError::message($e);

            return;
        }
        unset($this->customer, $this->statement);
        $this->modal = null;
        $this->notice = __('customers::customers.account.received', ['amount' => money($payment->amount), 'name' => $this->customer->name, 'balance' => money($this->customer->balance())]);
    }

    public function create(): void
    {
        $this->error = null;
        try {
            // Only these three fields come from the till; account permission and credit limit are back-office settings.
            $customer = app(CustomerService::class)->create(Arr::only($this->newCustomer, ['name', 'phone', 'address']), auth()->user());
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }
        unset($this->matches);
        $this->select($customer->id);
        $this->notice = __('customers::customers.pos.created', ['name' => $customer->name]);
    }

    public function render()
    {
        return view('customers::livewire.customers-screen', [
            'methods' => [PaymentMethod::Cash, PaymentMethod::MobileMoney, PaymentMethod::Card],
            'providers' => MomoProvider::cases(),
        ]);
    }
}
