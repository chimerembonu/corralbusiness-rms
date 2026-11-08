<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Models\DayClose;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Pos\Concerns\AsksForManagerPin;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Printing\Documents\CashReportDocument;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Money\Money;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The Cash screen: open the till with a float, pay in / pay out, cash up (count the drawer),
 * see today's X figures, close the day (Z report) and reopen it with a manager PIN.
 */
#[Layout('pos::layouts.app', ['title' => 'Cash'])]
class CashScreen extends Component
{
    use AsksForManagerPin, ShowsFriendlyErrors;

    /** Order to go back to once the till is open (the payment screen sent us here). */
    #[Url]
    public ?string $then = null;

    public string $date;

    /** open | pay_in | pay_out | cash_up | close_day | reopen | null */
    public ?string $modal = null;

    public string $amount = '';

    public string $reason = '';

    public string $reference = '';

    public string $notes = '';

    /** Count sheet: note value in pesewas → how many (strings from the inputs). */
    public array $count = [];

    public ?string $tillId = null;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(OrderService $orders): void
    {
        $user = auth()->user();
        abort_unless(
            $user->canAny([Permission::PaymentsTake->value, Permission::CashDrawer->value, Permission::DayClose->value, Permission::ReportsView->value]),
            403
        );

        $this->date = $orders->businessDate();
        $this->notice = session('status');
        $this->error = session('error');
    }

    #[Computed]
    public function session(): ?CashierSession
    {
        return app(CashService::class)->current(auth()->user());
    }

    #[Computed]
    public function expected(): ?Money
    {
        return $this->session ? app(CashService::class)->expected($this->session) : null;
    }

    #[Computed]
    public function till(): ?Till
    {
        return app(CurrentTill::class)->get();
    }

    /** @return Collection<int, Till> */
    #[Computed]
    public function tills(): Collection
    {
        return Till::query()->active()->orderBy('name')->get();
    }

    /** @return Collection<int, CashierSession> */
    #[Computed]
    public function sessions(): Collection
    {
        return app(CashService::class)->forDate($this->date);
    }

    #[Computed]
    public function dayClose(): ?DayClose
    {
        return app(DayCloseService::class)->closedDay($this->date);
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function report(): ?array
    {
        return auth()->user()->can(Permission::ReportsView->value) ? app(DayCloseService::class)->report($this->date) : null;
    }

    /** @return array<int, string> */
    #[Computed]
    public function blockers(): array
    {
        return app(DayCloseService::class)->blockers($this->date);
    }

    #[Computed]
    public function seesExpected(): bool
    {
        // Cashiers count blind; managers see what should be in the drawer.
        return auth()->user()->can(Permission::CashVariance->value);
    }

    public function openModal(string $name, TillSettings $settings): void
    {
        $this->modal = $name;
        $this->error = null;
        $this->notice = null;
        $this->amount = $name === 'open' ? Money::of($settings->defaultFloat())->toDecimal() : '';
        $this->reason = '';
        $this->reference = '';
        $this->notes = '';
        // One key per note value so the browser sees an object (a JSON list would not be reactive).
        $this->count = array_fill_keys(array_map('strval', CashService::DENOMINATIONS), '');
        $this->tillId = $this->till?->id;
    }

    public function closeModal(): void
    {
        $this->modal = null;
    }

    public function setReason(string $reason): void
    {
        $this->reason = $reason;
    }

    /** Open the till: count the float. A device without a till picks one here and remembers it. */
    public function openTill(CashService $cash, CurrentTill $current): void
    {
        $this->error = null;
        $till = $this->tillId ? Till::query()->active()->forBranch()->find($this->tillId) : null;

        try {
            $cash->open($till, auth()->user(), Money::fromDecimal($this->amount === '' ? '0' : $this->amount), $this->date);
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }

        if ($till && ! $this->till) {
            $current->remember($till);
        }
        $this->modal = null;
        unset($this->session, $this->sessions, $this->report, $this->till, $this->expected);

        if ($this->then) {
            $this->redirectRoute('pos.pay', $this->then);

            return;
        }
        $this->notice = __('pos::pos.cash.opened', ['till' => $till?->name ?? __('payments::payments.cash.no_till')]);
    }

    public function recordMovement(CashService $cash): void
    {
        $this->error = null;
        if (! $this->session) {
            $this->error = __('payments::payments.cash.errors.no_session');

            return;
        }

        $amount = Money::fromDecimal($this->amount === '' ? '0' : $this->amount);
        if ($this->modal !== 'pay_out') {
            $this->saveMovement(fn () => $cash->payIn($this->session, $amount, $this->reason, auth()->user(), $this->reference));

            return;
        }

        // A pay-out above the limit opens the manager keypad and is retried with their approval (security review M3).
        $args = [$amount->amount, $this->reason, $this->reference];
        $this->withManagerFallback(
            fn () => $this->payOutApproved(null, ...$args),
            Permission::CashPayOutLarge, 'payOutApproved', $args,
            __('pos::pos.approval.pay_out', ['amount' => money($amount)]),
        );
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function payOutApproved(?User $manager, int $amount, string $reason, string $reference): void
    {
        $this->saveMovement(fn () => app(CashService::class)->payOut($this->session, Money::of($amount), $reason, auth()->user(), $reference, $manager), $manager !== null);
    }

    private function saveMovement(callable $record, bool $reopenOnError = false): void
    {
        try {
            $movement = $record();
        } catch (ManagerPinRequiredException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
            if ($reopenOnError) {
                $this->modal = 'pay_out';
            }

            return;
        }

        $this->modal = null;
        unset($this->session, $this->sessions, $this->report, $this->expected);
        $this->notice = __('pos::pos.cash.movement_saved', ['type' => $movement->type->label(), 'amount' => money($movement->amount)]);
    }

    /** What the count sheet adds up to; falls back to the typed total when no notes were counted. */
    public function countedMoney(): Money
    {
        $breakdown = array_filter(array_map('intval', $this->count));

        return $breakdown ? CashService::countFromBreakdown($breakdown) : Money::fromDecimal($this->amount === '' ? '0' : $this->amount);
    }

    public function cashUp(): void
    {
        $this->error = null;
        if (! $this->session) {
            $this->error = __('payments::payments.cash.errors.no_session');

            return;
        }
        $counted = $this->countedMoney();
        $breakdown = array_filter(array_map('intval', $this->count));

        $this->withManagerFallback(
            fn () => $this->finishCashUp($counted->amount, $breakdown, $this->notes, null),
            Permission::CashVariance,
            'cashUpApproved',
            [$counted->amount, $breakdown, $this->notes],
            __('pos::pos.approval.cash_variance', ['amount' => money($counted)])
        );
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function cashUpApproved(User $manager, int $counted, array $breakdown, string $notes): void
    {
        $this->finishCashUp($counted, $breakdown, $notes, $manager);
    }

    private function finishCashUp(int $counted, array $breakdown, string $notes, ?User $approvedBy): void
    {
        try {
            $session = app(CashService::class)->close($this->session, Money::of($counted), auth()->user(), $breakdown, $notes, $approvedBy);
        } catch (RuntimeException $e) {
            if ($e instanceof ManagerPinRequiredException) {
                throw $e;
            }
            $this->error = FriendlyError::message($e);

            return;
        }

        $this->modal = null;
        unset($this->session, $this->sessions, $this->report, $this->expected, $this->blockers);
        $this->notice = __('pos::pos.cash.cashed_up', ['variance' => CashReportDocument::signed($session->variance, true)]);
    }

    public function reprintCashUp(string $sessionId, PrintService $printing): void
    {
        $session = CashierSession::query()->forBranch()->findOrFail($sessionId);
        $jobs = $printing->printCashUp($session);
        $this->notice = $jobs->isEmpty() ? __('pos::pos.orders.no_printer') : __('pos::pos.cash.printing');
    }

    public function printX(PrintService $printing): void
    {
        abort_unless(auth()->user()->can(Permission::ReportsView->value), 403);
        $jobs = $printing->printXReport(app(DayCloseService::class)->report($this->date));
        $this->notice = $jobs->isEmpty() ? __('pos::pos.orders.no_printer') : __('pos::pos.cash.printing');
    }

    public function closeDay(): void
    {
        $this->error = null;
        if ($blockers = $this->blockers) {
            $this->error = implode(' ', $blockers);

            return;
        }
        $notes = $this->notes;
        $this->modal = null;
        $this->askManager(Permission::DayClose, 'closeDayApproved', [$notes], __('pos::pos.approval.close_day', ['date' => Carbon::parse($this->date)->format('d/m/Y')]),
            second: app(DayCloseService::class)->needsSecondApproval());
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function closeDayApproved(User $manager, string $notes, ?User $second = null): void
    {
        try {
            $close = app(DayCloseService::class)->close($this->date, auth()->user(), $manager, $notes, secondApprover: $second);
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }

        unset($this->dayClose, $this->report, $this->blockers, $this->sessions);
        $this->notice = __('pos::pos.cash.day_closed', ['number' => $close->displayNumber()]);
    }

    public function reopenDay(): void
    {
        $this->error = null;
        if (blank($this->reason)) {
            $this->error = __('orders::orders.errors.reason_required');

            return;
        }
        $reason = $this->reason;
        $this->modal = null;
        $this->askManager(Permission::DayReopen, 'reopenApproved', [$reason], __('pos::pos.approval.reopen_day'));
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function reopenApproved(User $manager, string $reason): void
    {
        if (! $this->dayClose) {
            return;
        }

        try {
            app(DayCloseService::class)->reopen($this->dayClose, $manager, $reason);
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }

        unset($this->dayClose, $this->report, $this->blockers);
        $this->notice = __('payments::payments.day.reopened');
    }

    public function render(TillSettings $settings)
    {
        return view('pos::livewire.cash-screen', [
            'denominations' => CashService::DENOMINATIONS,
            'payInReasons' => $settings->payInReasons(),
            'payOutReasons' => $settings->payOutReasons(),
        ]);
    }
}
