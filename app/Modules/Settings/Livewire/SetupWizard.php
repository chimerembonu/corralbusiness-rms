<?php

namespace App\Modules\Settings\Livewire;

use App\Modules\Inventory\Enums\CostMethod;
use App\Modules\Printing\Enums\PrinterDriverType;
use App\Modules\Printing\Exceptions\PrintFailedException;
use App\Modules\Printing\Services\PrinterDiscovery;
use App\Modules\Settings\Services\BackupService;
use App\Modules\Settings\Services\SetupService;
use App\Modules\Settings\Tax\GhanaTaxDefaults;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Tax\TaxIdentificationNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * First-run setup wizard (Complete Edition E1.3) at /setup: restaurant, owner, taxes, receipt, tills and
 * kitchen stations, printers (found on the network and USB, test page, assigned: E1.4), menu and staff from Excel, and the backup password, then everything is created in one go and the owner
 * is signed in. Only open while the server has no restaurant. No command line needed.
 */
#[Layout('settings::layouts.setup')]
class SetupWizard extends Component
{
    use ShowsFriendlyErrors;
    use WithFileUploads;

    public const STEPS = ['welcome', 'restaurant', 'owner', 'taxes', 'receipt', 'tills', 'printers', 'data', 'backup', 'review'];

    public const MAX_TILLS = 12;

    public const MAX_STATIONS = 8;

    public const MAX_PRINTERS = 12;

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

    /** How stock is valued (CostMethod); the recommended method is chosen already. */
    public string $costMethod = 'average';

    // Receipt
    public string $receiptHeader = '';

    public string $receiptFooter = '';

    public bool $showServer = true;

    // Tills and kitchen stations
    public array $tills = ['Till 1'];

    public array $stations = [];

    // Printers: key => [name, driver, address, paper_width, tills [till positions], stations [station positions], drawer]
    public array $printers = [];

    public string $scanNetwork = '';

    /** What the last search found: network addresses, USB queues. */
    #[Locked]
    public array $found = ['searched' => false, 'subnet' => null, 'network' => [], 'usb' => []];

    /** Test page results: printer key => [ok, message]. */
    #[Locked]
    public array $tested = [];

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
        $this->stations = [__('settings::settings.setup.tills.default_station')];
    }

    /**
     * Every button press: once the restaurant exists the wizard is closed, except the final "done" screen for the
     * owner just signed in. A redirect alone would still run the button's method (E9.6 review M1), so it stops here.
     */
    public function hydrate(): void
    {
        $open = app(SetupService::class)->isOpen();
        abort_unless($open || ($this->step === 'done' && auth()->check()), 404);
    }

    public function unlock(SetupService $setup): void
    {
        if (! $setup->hasCode()) {
            $this->error = __('settings::settings.setup.errors.no_code');

            return;
        }
        // 5 wrong codes a minute per device, and 30 an hour from all devices together (E9.6 review L5).
        $key = 'setup-code:'.request()->ip();
        foreach ([$key => 5, 'setup-code:all' => 30] as $limit => $max) {
            if (RateLimiter::tooManyAttempts($limit, $max)) {
                $this->error = __('settings::settings.setup.errors.code_wait', ['seconds' => RateLimiter::availableIn($limit)]);

                return;
            }
        }
        if (! $setup->codeMatches($this->code)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit('setup-code:all', 3600);
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
        if (count($this->tills) < self::MAX_TILLS) {
            $this->tills[] = __('settings::settings.setup.tills.default_name', ['n' => count($this->tills) + 1]);
        }
    }

    public function removeTill(): void
    {
        if (count($this->tills) > 1) {
            array_pop($this->tills);
            $this->forgetAssignment('tills', count($this->tills));
        }
    }

    public function addStation(): void
    {
        if (count($this->stations) < self::MAX_STATIONS) {
            $this->stations[] = '';
        }
    }

    public function removeStation(): void
    {
        if (count($this->stations) > 1) {
            array_pop($this->stations);
            $this->forgetAssignment('stations', count($this->stations));
        }
    }

    /** Looks for printers on the restaurant network (port 9100) and among this computer's print queues (USB). */
    public function searchPrinters(PrinterDiscovery $discovery): void
    {
        $this->error = null;
        if (! $this->unlocked || $this->step !== 'printers') {
            return;
        }
        $key = 'setup-printer-search:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            $this->error = __('settings::settings.setup.errors.printer_wait', ['seconds' => RateLimiter::availableIn($key)]);

            return;
        }
        RateLimiter::hit($key, 60);

        $this->found = ['searched' => true, 'subnet' => null, 'network' => [], 'usb' => array_column($discovery->usbQueues(), 'name')];
        try {
            $scan = $discovery->scanNetwork(trim($this->scanNetwork) ?: null);
            $this->found['subnet'] = $this->scanNetwork = $scan['subnet'];
            $this->found['network'] = $scan['addresses'];
        } catch (PrintFailedException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Adds a printer: one that the search found (driver + address), or an empty one to fill in by hand. */
    public function addPrinter(string $driver = 'network', string $address = ''): void
    {
        if (! $this->unlocked || count($this->printers) >= self::MAX_PRINTERS || ! in_array($driver, ['network', 'usb'], true)) {
            return;
        }
        $address = trim($address);
        if ($address !== '' && collect($this->printers)->contains(fn ($p) => ($p['address'] ?? '') === $address)) {
            return; // already added
        }
        $this->printers[Str::lower(Str::random(8))] = [
            'name' => __('settings::settings.setup.printers.default_name', ['n' => count($this->printers) + 1]),
            'driver' => $driver,
            'address' => $address,
            'paper_width' => '80',
            'tills' => [],
            'stations' => [],
            'drawer' => false,
        ];
    }

    public function removePrinter(string $key): void
    {
        unset($this->printers[$key], $this->tested[$key]);
    }

    /** Prints a test page on one printer straight away and shows whether it worked. */
    public function testPrinter(string $key, PrinterDiscovery $discovery): void
    {
        $this->error = null;
        if (! $this->unlocked || ! isset($this->printers[$key])) {
            return;
        }
        $limit = 'setup-printer-test:'.request()->ip();
        if (RateLimiter::tooManyAttempts($limit, 20)) {
            $this->error = __('settings::settings.setup.errors.printer_wait', ['seconds' => RateLimiter::availableIn($limit)]);

            return;
        }
        RateLimiter::hit($limit, 60);

        $printer = $this->printers[$key];
        try {
            $discovery->testPrint($printer);
            $this->tested[$key] = ['ok' => true, 'message' => __('settings::settings.setup.printers.test_ok', ['printer' => $printer['name']])];
        } catch (PrintFailedException $e) {
            $this->tested[$key] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** A till or station was removed: printers can no longer be assigned to it. */
    private function forgetAssignment(string $list, int $position): void
    {
        foreach ($this->printers as $key => $printer) {
            $this->printers[$key][$list] = array_values(array_filter($printer[$list] ?? [], fn ($i) => (int) $i !== $position));
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
            'cost_method' => $this->costMethod,
            'receipt_header' => $this->receiptHeader,
            'receipt_footer' => $this->receiptFooter,
            'show_server' => $this->showServer,
            'tills' => $this->tills,
            'stations' => $this->stations,
            'printers' => array_values($this->printers),
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

    /** Shown only on the backup and last screens, after the setup code (E9.6 review M1: it was a public method). */
    private function backupPassword(): ?string
    {
        return $this->unlocked && in_array($this->step, ['backup', 'done'], true) ? app(BackupService::class)->password() : null;
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
                ['rates' => ['array'], 'rates.*' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'], 'pricesIncludeTax' => ['required', 'in:yes,no'],
                    'costMethod' => ['required', Rule::enum(CostMethod::class)]],
                ['pricesIncludeTax.required' => __('settings::settings.setup.errors.prices_include_tax')],
                ['rates.*' => $a('rate')],
            ),
            'receipt' => $this->validate(
                ['receiptHeader' => ['nullable', 'string', 'max:500'], 'receiptFooter' => ['nullable', 'string', 'max:500']],
                [],
                ['receiptHeader' => $a('receipt_header'), 'receiptFooter' => $a('receipt_footer')],
            ),
            'tills' => $this->validate(
                [
                    'tills' => ['required', 'array', 'min:1', 'max:'.self::MAX_TILLS], 'tills.*' => ['required', 'string', 'max:40', 'distinct'],
                    'stations' => ['required', 'array', 'min:1', 'max:'.self::MAX_STATIONS], 'stations.*' => ['required', 'string', 'max:50', 'distinct'],
                ],
                [],
                ['tills.*' => $a('till_name'), 'stations.*' => $a('station_name')],
            ),
            'printers' => $this->validatePrinters(),
            'data' => $this->validate(
                ['menuFile' => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:5120'], 'staffFile' => ['nullable', 'file', 'mimes:xlsx,xls,csv', 'max:5120']],
                [],
                ['menuFile' => $a('menu_file'), 'staffFile' => $a('staff_file')],
            ),
            'backup' => $this->validate(
                ['backupWritten' => ['accepted'], 'backupCheck' => ['required', function ($attr, $value, $fail) {
                    if (! hash_equals(substr(app(BackupService::class)->password(), -4), trim((string) $value))) {
                        $fail(__('settings::settings.setup.errors.backup_check'));
                    }
                }]],
                ['backupWritten.accepted' => __('settings::settings.setup.errors.backup_written')],
                ['backupCheck' => $a('backup_check')],
            ),
            default => null,
        };
    }

    private function validatePrinters(): void
    {
        $a = fn (string $k) => __('settings::settings.setup.fields.'.$k);

        // Every printer does something, and each till sends its receipts to one printer only.
        $problems = [];
        $claimed = [];
        foreach ($this->printers as $key => $printer) {
            if (($printer['tills'] ?? []) === [] && ($printer['stations'] ?? []) === []) {
                $problems["printers.{$key}.tills"] = __('settings::settings.setup.errors.printer_unused');
            }
            foreach ((array) ($printer['tills'] ?? []) as $till) {
                if (isset($claimed[(int) $till])) {
                    $problems["printers.{$key}.tills"] = __('settings::settings.setup.errors.till_twice', ['till' => $this->tills[(int) $till] ?? '']);
                }
                $claimed[(int) $till] = true;
            }
        }

        // Reported together with the field mistakes, so every problem shows at once.
        $this->withValidator(fn ($validator) => $validator->after(function ($v) use ($problems) {
            foreach ($problems as $field => $message) {
                $v->errors()->add($field, $message);
            }
        }))->validate([
            'printers' => ['array', 'max:'.self::MAX_PRINTERS],
            'printers.*.name' => ['required', 'string', 'max:50', 'distinct'],
            'printers.*.driver' => ['required', 'in:network,usb'],
            'printers.*.address' => ['required', 'string', 'distinct', function ($attr, $value, $fail) {
                $driver = PrinterDriverType::tryFrom((string) data_get($this->printers, str_replace(['printers.', '.address'], '', $attr).'.driver'));
                if (! $driver || ! PrinterDiscovery::validAddress($driver, (string) $value)) {
                    $fail(__('printing::printing.discovery.bad_address', ['address' => $value]));
                }
            }],
            'printers.*.paper_width' => ['required', 'in:58,80'],
            'printers.*.tills' => ['array'],
            'printers.*.tills.*' => ['integer', 'min:0', 'max:'.(count($this->tills) - 1)],
            'printers.*.stations' => ['array'],
            'printers.*.stations.*' => ['integer', 'min:0', 'max:'.(count($this->stations) - 1)],
            'printers.*.drawer' => ['boolean'],
        ], [], ['printers.*.name' => $a('printer_name'), 'printers.*.address' => $a('printer_address')]);
    }

    public function render()
    {
        return view('settings::livewire.setup-wizard', [
            'steps' => self::STEPS,
            'position' => array_search($this->step, self::STEPS, true),
            'taxNames' => collect(GhanaTaxDefaults::components())->pluck('name', 'code')->all(),
            'drivers' => ['network' => PrinterDriverType::Network->label(), 'usb' => PrinterDriverType::Usb->label()],
            'backupPassword' => $this->backupPassword(),
        ]);
    }
}
