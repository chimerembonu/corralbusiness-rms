<?php

namespace App\Modules\Settings\Livewire;

use App\Modules\Settings\Services\BackupService;
use App\Modules\Settings\Services\SetupService;
use App\Modules\Settings\Tax\GhanaTaxDefaults;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Tax\TaxIdentificationNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * First-run setup wizard (Complete Edition E1.3) at /setup: restaurant, owner, taxes, receipt, tills,
 * menu and staff from Excel, and the backup password, then everything is created in one go and the owner
 * is signed in. Only open while the server has no restaurant. No command line needed.
 */
#[Layout('settings::layouts.setup')]
class SetupWizard extends Component
{
    use ShowsFriendlyErrors;
    use WithFileUploads;

    public const STEPS = ['welcome', 'restaurant', 'owner', 'taxes', 'receipt', 'tills', 'data', 'backup', 'review'];

    #[Locked]
    public string $step = 'welcome';

    #[Locked]
    public bool $unlocked = false;

    #[Locked]
    public array $results = [];

    public ?string $error = null;

    public string $code = '';

    // Restaurant
    public string $restaurant = '';

    public string $branch = 'Main Branch';

    public string $address = '';

    public string $phone = '';

    public string $tin = '';

    // Owner
    public string $ownerName = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $pin = '';

    public string $pin_confirmation = '';

    // Taxes: code => percent as typed
    public array $rates = [];

    public string $pricesIncludeTax = '';

    // Receipt
    public string $receiptHeader = '';

    public string $receiptFooter = '';

    public bool $showServer = true;

    // Tills
    public array $tills = ['Till 1'];

    // Menu and staff from Excel (optional)
    public ?TemporaryUploadedFile $menuFile = null;

    public ?TemporaryUploadedFile $staffFile = null;

    // Backup password
    public bool $backupWritten = false;

    public string $backupCheck = '';

    public function mount(SetupService $setup): void
    {
        if (! $setup->isOpen()) {
            $this->redirect(route('home'));

            return;
        }
        $this->unlocked = ! $setup->codeRequired();
        foreach (GhanaTaxDefaults::components() as $c) {
            $this->rates[$c['code']] = number_format($c['rate_basis_points'] / 100, 2);
        }
        $this->receiptFooter = __('settings::settings.setup.receipt.default_footer');
    }

    /** Every button press: once the restaurant exists the wizard is closed (except the final "done" screen). */
    public function hydrate(): void
    {
        if ($this->step !== 'done' && ! app(SetupService::class)->isOpen()) {
            $this->redirect(route('home'));
        }
    }

    public function unlock(SetupService $setup): void
    {
        $key = 'setup-code:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = __('settings::settings.setup.errors.code_wait', ['seconds' => RateLimiter::availableIn($key)]);

            return;
        }
        if (! $setup->codeMatches($this->code)) {
            RateLimiter::hit($key, 60);
            $this->error = __('settings::settings.setup.errors.code_wrong');

            return;
        }
        RateLimiter::clear($key);
        $this->unlocked = true;
        $this->error = null;
        $this->next();
    }

    public function next(): void
    {
        $this->error = null;
        if (! $this->unlocked) {
            return;
        }
        $this->validateStep($this->step);
        if ($this->step === 'restaurant' && trim($this->receiptHeader) === '') {
            $this->receiptHeader = implode("\n", array_filter([trim($this->restaurant), trim($this->address), trim($this->phone)]));
        }
        $i = array_search($this->step, self::STEPS, true);
        $this->step = self::STEPS[min($i + 1, count(self::STEPS) - 1)];
    }

    public function back(): void
    {
        $this->error = null;
        $i = array_search($this->step, self::STEPS, true);
        if ($i > 1) {
            $this->step = self::STEPS[$i - 1];
        }
    }

    public function addTill(): void
    {
        if (count($this->tills) < 12) {
            $this->tills[] = __('settings::settings.setup.tills.default_name', ['n' => count($this->tills) + 1]);
        }
    }

    public function removeTill(): void
    {
        if (count($this->tills) > 1) {
            array_pop($this->tills);
        }
    }

    public function finish(SetupService $setup): void
    {
        $this->error = null;
        if (! $this->unlocked) {
            return;
        }
        foreach (array_slice(self::STEPS, 1, -1) as $step) {
            try {
                $this->validateStep($step);
            } catch (ValidationException $e) {
                $this->step = $step; // take them back to the screen with the mistake

                throw $e;
            }
        }

        $created = $setup->complete([
            'restaurant' => trim($this->restaurant),
            'branch' => trim($this->branch),
            'address' => trim($this->address) ?: null,
            'phone' => trim($this->phone) ?: null,
            'tin' => TaxIdentificationNumber::normalise($this->tin) ?: null,
            'owner_name' => trim($this->ownerName),
            'email' => strtolower(trim($this->email)),
            'password' => $this->password,
            'pin' => $this->pin,
            'taxes' => array_map(fn ($percent) => (int) round(((float) $percent) * 100), $this->rates),
            'prices_include_tax' => $this->pricesIncludeTax === 'yes',
            'receipt_header' => $this->receiptHeader,
            'receipt_footer' => $this->receiptFooter,
            'show_server' => $this->showServer,
            'tills' => $this->tills,
        ]);

        // The restaurant exists now: a problem in a spreadsheet must not undo it, so it is reported instead.
        $files = array_filter(['menu' => $this->menuFile?->getRealPath(), 'staff' => $this->staffFile?->getRealPath()]);
        try {
            foreach ($setup->import($created['branch'], $created['owner'], $files) as $type => $result) {
                $this->results[$type] = ['summary' => $result->summary(), 'errors' => $result->count('error')];
            }
        } catch (Throwable $e) {
            $this->results['problem'] = FriendlyError::message($e);
        }

        Auth::login($created['owner']);
        session()->regenerate();
        $this->password = $this->password_confirmation = $this->pin = $this->pin_confirmation = '';
        $this->step = 'done';
    }

    public function backupPassword(): string
    {
        return app(BackupService::class)->password();
    }

    private function validateStep(string $step): void
    {
        $a = fn (string $k) => __('settings::settings.setup.fields.'.$k);
        match ($step) {
            'restaurant' => $this->validate([
                'restaurant' => ['required', 'string', 'max:120'],
                'branch' => ['required', 'string', 'max:120'],
                'address' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:30'],
                'tin' => ['nullable', 'string', function ($attr, $value, $fail) {
                    if (TaxIdentificationNumber::normalise($value) === false) {
                        $fail(__('settings::settings.setup.errors.tin'));
                    }
                }],
            ], [], ['restaurant' => $a('restaurant'), 'branch' => $a('branch'), 'address' => $a('address'), 'phone' => $a('phone'), 'tin' => $a('tin')]),
            'owner' => $this->validate([
                'ownerName' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'pin' => ['required', 'digits_between:'.config('rms.pin.min_length', 4).','.config('rms.pin.max_length', 6), 'confirmed'],
            ], ['pin.digits_between' => __('settings::settings.setup.errors.pin')], ['ownerName' => $a('owner_name'), 'email' => $a('email'), 'password' => $a('password'), 'pin' => $a('pin')]),
            'taxes' => $this->validate(
                ['rates' => ['array'], 'rates.*' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'], 'pricesIncludeTax' => ['required', 'in:yes,no']],
                ['pricesIncludeTax.required' => __('settings::settings.setup.errors.prices_include_tax')],
                ['rates.*' => $a('rate')],
            ),
            'receipt' => $this->validate(
                ['receiptHeader' => ['nullable', 'string', 'max:500'], 'receiptFooter' => ['nullable', 'string', 'max:500']],
                [],
                ['receiptHeader' => $a('receipt_header'), 'receiptFooter' => $a('receipt_footer')],
            ),
            'tills' => $this->validate(
                ['tills' => ['required', 'array', 'min:1', 'max:12'], 'tills.*' => ['required', 'string', 'max:40', 'distinct']],
                [],
                ['tills.*' => $a('till_name')],
            ),
            'data' => $this->validate(
                ['menuFile' => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:5120'], 'staffFile' => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:5120']],
                [],
                ['menuFile' => $a('menu_file'), 'staffFile' => $a('staff_file')],
            ),
            'backup' => $this->validate(
                ['backupWritten' => ['accepted'], 'backupCheck' => ['required', function ($attr, $value, $fail) {
                    if (! hash_equals(substr($this->backupPassword(), -4), trim((string) $value))) {
                        $fail(__('settings::settings.setup.errors.backup_check'));
                    }
                }]],
                ['backupWritten.accepted' => __('settings::settings.setup.errors.backup_written')],
                ['backupCheck' => $a('backup_check')],
            ),
            default => null,
        };
    }

    public function render()
    {
        return view('settings::livewire.setup-wizard', [
            'steps' => self::STEPS,
            'position' => array_search($this->step, self::STEPS, true),
            'taxNames' => collect(GhanaTaxDefaults::components())->pluck('name', 'code')->all(),
        ]);
    }
}
