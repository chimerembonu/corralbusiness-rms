<?php

use App\Modules\Customers\Enums\AccountTransactionType;
use App\Modules\Customers\Events\AccountCharged;
use App\Modules\Customers\Events\AccountPaymentReceived;
use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->accounts = app(AccountService::class);
    $this->orders = app(OrderService::class);
    $this->hotel = app(CustomerService::class)->create(['name' => 'Golden Tulip', 'phone' => '0302213344', 'allow_account' => true, 'credit_limit' => Money::fromDecimal('100')]);
    $this->walkIn = app(CustomerService::class)->create(['name' => 'Walk-in', 'phone' => '0200000000']);
});

function accountOrder(int $cokes)
{
    $order = test()->orders->open(OrderType::Takeaway, test()->cashier);
    test()->orders->addItem($order, test()->menu['coke'], quantity: $cokes); // 12.00 each

    return $order;
}

it('charges an order to the account within the credit limit and settles it later into the drawer', function () {
    Event::fake([AccountCharged::class, AccountPaymentReceived::class]);
    $session = app(CashService::class)->open(Till::create(['name' => 'T1', 'code' => 'T1']), $this->cashier, Money::fromDecimal('100'), now()->toDateString());

    $order = accountOrder(5); // 60.00
    expect(fn () => $this->accounts->charge($order, null, $this->cashier))->toThrow(CustomerException::class, 'Put a customer on the order');
    app(CustomerService::class)->attach($order, $this->walkIn);
    expect(fn () => $this->accounts->charge($order->fresh(), null, $this->cashier))->toThrow(CustomerException::class, 'not allowed to buy on account');

    app(CustomerService::class)->attach($order, $this->hotel);
    $payment = $this->accounts->charge($order->fresh(), null, $this->cashier);

    expect($payment->method)->toBe(PaymentMethod::Account)->and($payment->amount)->toBePesewas(6000)->and($payment->customer_id)->toBe($this->hotel->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->hotel->fresh()->account_balance)->toBe(6000)
        ->and($this->hotel->fresh()->creditAvailable())->toBePesewas(4000)
        ->and($this->hotel->fresh()->orders_count)->toBe(1)
        ->and(app(CashService::class)->expected($session))->toBePesewas(10000); // nothing in the drawer yet
    Event::assertDispatched(AccountCharged::class);

    // Over the limit → refused; the order stays unpaid.
    $big = accountOrder(5);
    app(CustomerService::class)->attach($big, $this->hotel);
    expect(fn () => $this->accounts->charge($big->fresh(), null, $this->cashier))->toThrow(CustomerException::class, 'can only put GH₵ 40.00 more');
    expect($big->fresh()->status)->not->toBe(OrderStatus::Paid);

    // Part cash, rest on account.
    app(PaymentService::class)->payCash($big->fresh(), Money::fromDecimal('30'), $this->cashier);
    $this->accounts->charge($big->fresh(), Money::fromDecimal('30'), $this->cashier);
    expect($big->fresh()->status)->toBe(OrderStatus::Paid)->and($this->hotel->fresh()->account_balance)->toBe(9000);

    // The hotel pays GHS 50 in cash and GHS 40 by MoMo.
    $cash = $this->accounts->receivePayment($this->hotel, PaymentMethod::Cash, Money::fromDecimal('50'), $this->cashier);
    expect(fn () => $this->accounts->receivePayment($this->hotel, PaymentMethod::MobileMoney, Money::fromDecimal('40'), $this->cashier))->toThrow(PaymentException::class);
    expect(fn () => $this->accounts->receivePayment($this->hotel, PaymentMethod::Account, Money::fromDecimal('1'), $this->cashier))->toThrow(CustomerException::class);
    $momo = $this->accounts->receivePayment($this->hotel, PaymentMethod::MobileMoney, Money::fromDecimal('40'), $this->cashier, 'MP55', MomoProvider::Mtn);

    expect($cash->order_id)->toBeNull()->and($cash->cashier_session_id)->toBe($session->id)->and($cash->customer_id)->toBe($this->hotel->id)
        ->and($momo->provider)->toBe(MomoProvider::Mtn)->and($momo->reference)->toBe('MP55')
        ->and($this->hotel->fresh()->account_balance)->toBe(0)
        ->and(app(CashService::class)->expected($session))->toBePesewas(10000 + 3000 + 5000); // cash for the order + cash against the account
    Event::assertDispatched(AccountPaymentReceived::class);

    $statement = $this->accounts->statement($this->hotel);
    expect($statement->pluck('type')->map->value->all())->toBe(['charge', 'charge', 'payment', 'payment'])
        ->and($statement->last()->balance_after)->toBe(0)
        ->and($statement->first()->notes)->toBe('Order #'.$order->fresh()->displayNumber())
        ->and(OutboxEvent::query()->whereIn('event_type', ['customers.account_charged', 'customers.account_payment_received'])->count())->toBe(0); // faked above
});

it('lets a manager adjust a balance with a reason', function () {
    expect(fn () => $this->accounts->adjust($this->hotel, Money::fromDecimal('-10'), $this->cashier, 'Goodwill'))->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->accounts->adjust($this->hotel, Money::fromDecimal('-10'), $this->manager, ''))->toThrow(CustomerException::class);
    $t = $this->accounts->adjust($this->hotel, Money::fromDecimal('-10'), $this->manager, 'Goodwill write-off');
    expect($t->type)->toBe(AccountTransactionType::Adjustment)->and($this->hotel->fresh()->account_balance)->toBe(-1000)
        ->and($this->accounts->debtors())->toHaveCount(0);
});
