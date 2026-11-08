@php($w = fn (string $k, array $r = []) => __('settings::settings.setup.'.$k, $r))
@php($input = 'mt-1 block min-h-12 w-full rounded-xl border border-slate-700 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500 focus:border-emerald-400 focus:outline-none')
@php($label = 'block text-sm font-semibold text-slate-300')
@php($primary = 'min-h-12 rounded-xl bg-emerald-500 px-6 text-base font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-50')
@php($secondary = 'min-h-12 rounded-xl bg-slate-800 px-6 text-base font-semibold hover:bg-slate-700')
<div class="mx-auto flex min-h-full max-w-2xl flex-col gap-6 px-4 py-8">
    <header class="flex items-center gap-3">
        <img src="{{ asset(config('rms.brand.mark')) }}" alt="" class="h-10 w-10">
        <div>
            <h1 class="text-2xl font-black">{{ $w('title') }}</h1>
            <p class="text-sm text-slate-400">{{ __('rms.product_name') }}</p>
        </div>
    </header>

    @if ($step !== 'done' && $step !== 'welcome')
        <ol class="flex gap-1" aria-label="{{ $w('progress') }}">
            @foreach (array_slice($steps, 1) as $i => $s)
                <li class="h-2 flex-1 rounded-full {{ $i + 1 <= $position ? 'bg-emerald-400' : 'bg-slate-700' }}" title="{{ $w('steps.'.$s) }}"></li>
            @endforeach
        </ol>
        <p class="-mt-4 text-sm text-slate-400">{{ $w('step_of', ['n' => $position, 'total' => count($steps) - 1, 'name' => $w('steps.'.$step)]) }}</p>
    @endif

    @if ($error)
        <p class="rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>
    @endif

    {{-- Keyed by step: each screen gets fresh inputs, so a box can never stay bound to the previous screen's field. --}}
    <section wire:key="step-{{ $step }}" class="flex flex-col gap-5 rounded-3xl border border-slate-800 bg-slate-900 p-6">
        @switch($step)
            @case('welcome')
                <h2 class="text-xl font-bold">{{ $w('welcome.heading') }}</h2>
                <p class="text-slate-300">{{ $w('welcome.body') }}</p>
                <ul class="list-disc space-y-1 pl-5 text-sm text-slate-400">
                    @foreach (['restaurant', 'owner', 'taxes', 'receipt', 'tills', 'printers', 'data', 'backup'] as $s)
                        <li>{{ $w('steps.'.$s) }}</li>
                    @endforeach
                </ul>
                @if (! $unlocked)
                    <form wire:submit="unlock" class="flex flex-col gap-3">
                        <label class="{{ $label }}">{{ $w('fields.code') }}
                            <input type="text" inputmode="numeric" autocomplete="one-time-code" wire:model="code" class="{{ $input }} tracking-widest">
                        </label>
                        <p class="text-sm text-slate-400">{{ $w('welcome.code_help') }}</p>
                        <button type="submit" class="{{ $primary }} self-start">{{ $w('start') }}</button>
                    </form>
                @else
                    <button type="button" wire:click="next" class="{{ $primary }} self-start">{{ $w('start') }}</button>
                @endif
                @break

            @case('restaurant')
                <h2 class="text-xl font-bold">{{ $w('steps.restaurant') }}</h2>
                @foreach (['restaurant' => 'text', 'branch' => 'text', 'address' => 'text', 'phone' => 'tel', 'tin' => 'text'] as $field => $type)
                    <label class="{{ $label }}" wire:key="field-{{ $field }}">{{ $w('fields.'.$field) }}
                        <input type="{{ $type }}" wire:model="{{ $field }}" class="{{ $input }}" @if ($field === 'tin') placeholder="C0012345678" @endif>
                        @error($field) <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <p class="text-sm text-slate-400">{{ $w('restaurant.tin_help') }}</p>
                @break

            @case('owner')
                <h2 class="text-xl font-bold">{{ $w('steps.owner') }}</h2>
                <p class="text-sm text-slate-400">{{ $w('owner.help') }}</p>
                @foreach (['ownerName' => ['owner_name', 'text'], 'email' => ['email', 'email'], 'password' => ['password', 'password'], 'password_confirmation' => ['password_confirmation', 'password'], 'pin' => ['pin', 'password'], 'pin_confirmation' => ['pin_confirmation', 'password']] as $field => [$key, $type])
                    <label class="{{ $label }}" wire:key="field-{{ $field }}">{{ $w('fields.'.$key) }}
                        <input type="{{ $type }}" wire:model="{{ $field }}" class="{{ $input }}" @if (str_starts_with($field, 'pin')) inputmode="numeric" maxlength="6" @endif autocomplete="{{ $type === 'password' ? 'new-password' : 'off' }}">
                        @error($field) <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                @break

            @case('taxes')
                <h2 class="text-xl font-bold">{{ $w('steps.taxes') }}</h2>
                <p class="rounded-xl bg-amber-500/15 px-4 py-3 text-sm text-amber-200">{{ $w('taxes.confirm') }}</p>
                @foreach ($rates as $code => $rate)
                    <label class="{{ $label }}" wire:key="rate-{{ $code }}">{{ $taxNames[$code] ?? $code }} (%)
                        <input type="text" inputmode="decimal" wire:model="rates.{{ $code }}" class="{{ $input }}">
                        @error('rates.'.$code) <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <fieldset class="flex flex-col gap-2">
                    <legend class="{{ $label }} mb-2">{{ $w('taxes.inclusive_question') }}</legend>
                    @foreach (['no', 'yes'] as $choice)
                        <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4">
                            <input type="radio" wire:model="pricesIncludeTax" value="{{ $choice }}" class="h-5 w-5">
                            <span>{{ $w('taxes.inclusive_'.$choice) }}</span>
                        </label>
                    @endforeach
                    @error('pricesIncludeTax') <span class="text-sm text-rose-300">{{ $message }}</span> @enderror
                </fieldset>
                <fieldset class="flex flex-col gap-2">
                    <legend class="{{ $label }} mb-1">{{ $w('taxes.cost_question') }}</legend>
                    <p class="mb-1 text-sm text-slate-400">{{ $w('taxes.cost_help') }}</p>
                    @foreach (\App\Modules\Inventory\Enums\CostMethod::cases() as $method)
                        <label class="flex min-h-12 items-start gap-3 rounded-xl bg-slate-800 px-4 py-3" wire:key="cost-{{ $method->value }}">
                            <input type="radio" wire:model="costMethod" value="{{ $method->value }}" class="mt-1 h-5 w-5 shrink-0">
                            <span><span class="font-semibold">{{ $method->label() }}</span><br><span class="text-sm text-slate-400">{{ $method->about() }}</span></span>
                        </label>
                    @endforeach
                    @error('costMethod') <span class="text-sm text-rose-300">{{ $message }}</span> @enderror
                </fieldset>
                @break

            @case('receipt')
                <h2 class="text-xl font-bold">{{ $w('steps.receipt') }}</h2>
                <label class="{{ $label }}">{{ $w('fields.receipt_header') }}
                    <textarea wire:model="receiptHeader" rows="4" class="{{ $input }} py-3"></textarea>
                    @error('receiptHeader') <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                </label>
                <label class="{{ $label }}">{{ $w('fields.receipt_footer') }}
                    <textarea wire:model="receiptFooter" rows="3" class="{{ $input }} py-3"></textarea>
                    @error('receiptFooter') <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                </label>
                <p class="text-sm text-slate-400">{{ $w('receipt.footer_help') }}</p>
                <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4">
                    <input type="checkbox" wire:model="showServer" class="h-5 w-5">
                    <span>{{ $w('fields.show_server') }}</span>
                </label>
                @break

            @case('tills')
                <h2 class="text-xl font-bold">{{ $w('steps.tills') }}</h2>
                <p class="text-sm text-slate-400">{{ $w('tills.help') }}</p>
                @foreach ($tills as $i => $name)
                    <label class="{{ $label }}" wire:key="till-{{ $i }}">{{ $w('fields.till_name') }} {{ $i + 1 }}
                        <input type="text" wire:model="tills.{{ $i }}" class="{{ $input }}">
                        @error('tills.'.$i) <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="addTill" class="{{ $secondary }}" @disabled(count($tills) >= 12)>{{ $w('tills.add') }}</button>
                    <button type="button" wire:click="removeTill" class="{{ $secondary }}" @disabled(count($tills) <= 1)>{{ $w('tills.remove') }}</button>
                </div>
                <h3 class="mt-2 text-lg font-bold">{{ $w('tills.stations_heading') }}</h3>
                <p class="text-sm text-slate-400">{{ $w('tills.stations_help') }}</p>
                @foreach ($stations as $i => $name)
                    <label class="{{ $label }}" wire:key="station-{{ $i }}">{{ $w('fields.station_name') }} {{ $i + 1 }}@if ($i === 0) · {{ $w('tills.station_default') }}@endif
                        <input type="text" wire:model="stations.{{ $i }}" class="{{ $input }}" @if ($i > 0) placeholder="{{ $w('tills.station_example') }}" @endif>
                        @error('stations.'.$i) <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                    </label>
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="addStation" class="{{ $secondary }}" @disabled(count($stations) >= 8)>{{ $w('tills.add_station') }}</button>
                    <button type="button" wire:click="removeStation" class="{{ $secondary }}" @disabled(count($stations) <= 1)>{{ $w('tills.remove') }}</button>
                </div>
                @break

            @case('printers')
                @php($added = collect($printers)->pluck('address')->all())
                <h2 class="text-xl font-bold">{{ $w('steps.printers') }}</h2>
                <p class="text-sm text-slate-400">{{ $w('printers.help') }}</p>
                <form wire:submit="searchPrinters" class="flex flex-col gap-3 rounded-2xl bg-slate-800 p-4">
                    <label class="{{ $label }}">{{ $w('fields.scan_network') }}
                        <input type="text" wire:model="scanNetwork" placeholder="{{ $w('printers.network_placeholder') }}" class="{{ $input }} bg-slate-900">
                    </label>
                    <button type="submit" wire:loading.attr="disabled" wire:target="searchPrinters" class="{{ $primary }} self-start">
                        <span wire:loading.remove wire:target="searchPrinters">{{ $w('printers.search') }}</span>
                        <span wire:loading wire:target="searchPrinters">{{ $w('printers.searching') }}</span>
                    </button>
                    @if ($found['searched'])
                        @foreach (array_filter(['network' => $found['subnet'] ? $found['network'] : null, 'usb' => $found['usb']], 'is_array') as $driver => $addresses)
                            <div class="flex flex-col gap-2">
                                <span class="text-sm font-semibold text-slate-300">{{ $w('printers.found_'.$driver, ['network' => $found['subnet'] ?? '—']) }}</span>
                                @forelse ($addresses as $address)
                                    <div class="flex min-h-12 items-center justify-between gap-3 rounded-xl bg-slate-900 px-4" wire:key="found-{{ $driver }}-{{ $address }}">
                                        <span class="min-w-0 truncate font-mono">{{ $address }}</span>
                                        @if (in_array($address, $added, true))
                                            <span class="shrink-0 text-sm text-emerald-300">{{ $w('printers.added') }}</span>
                                        @else
                                            <button type="button" wire:click="addPrinter('{{ $driver }}', @js($address))" class="{{ $secondary }} min-h-12 shrink-0 bg-slate-700 px-4">{{ $w('printers.use') }}</button>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-sm text-slate-400">{{ $w('printers.none_'.$driver) }}</p>
                                @endforelse
                            </div>
                        @endforeach
                    @endif
                </form>

                @foreach ($printers as $key => $printer)
                    <div class="flex flex-col gap-4 rounded-2xl border border-slate-700 p-4" wire:key="printer-{{ $key }}">
                        <label class="{{ $label }}">{{ $w('fields.printer_name') }}
                            <input type="text" wire:model="printers.{{ $key }}.name" class="{{ $input }}">
                            @error('printers.'.$key.'.name') <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="{{ $label }}">{{ $w('fields.printer_driver') }}
                                <select wire:model.live="printers.{{ $key }}.driver" class="{{ $input }}">
                                    @foreach ($drivers as $value => $name)
                                        <option value="{{ $value }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="{{ $label }}">{{ $w('fields.printer_address') }}
                                <input type="text" wire:model="printers.{{ $key }}.address" placeholder="{{ $printer['driver'] === 'usb' ? 'RMS_USB_1' : '192.168.8.50' }}" class="{{ $input }} font-mono">
                                @error('printers.'.$key.'.address') <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                            </label>
                        </div>
                        <fieldset class="flex flex-wrap gap-2">
                            <legend class="{{ $label }} mb-2">{{ $w('fields.paper_width') }}</legend>
                            @foreach (['80', '58'] as $width)
                                <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4">
                                    <input type="radio" wire:model="printers.{{ $key }}.paper_width" value="{{ $width }}" class="h-5 w-5">
                                    <span>{{ $width }} mm</span>
                                </label>
                            @endforeach
                        </fieldset>
                        <fieldset class="flex flex-wrap gap-2">
                            <legend class="{{ $label }} mb-2">{{ $w('printers.receipts_for') }}</legend>
                            @foreach ($tills as $i => $till)
                                <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4" wire:key="printer-{{ $key }}-till-{{ $i }}">
                                    <input type="checkbox" wire:model="printers.{{ $key }}.tills" value="{{ $i }}" class="h-5 w-5">
                                    <span>{{ $till }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                        <fieldset class="flex flex-wrap gap-2">
                            <legend class="{{ $label }} mb-2">{{ $w('printers.tickets_for') }}</legend>
                            @foreach ($stations as $i => $station)
                                <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4" wire:key="printer-{{ $key }}-station-{{ $i }}">
                                    <input type="checkbox" wire:model="printers.{{ $key }}.stations" value="{{ $i }}" class="h-5 w-5">
                                    <span>{{ $station }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                        @error('printers.'.$key.'.tills') <span class="text-sm text-rose-300">{{ $message }}</span> @enderror
                        <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4">
                            <input type="checkbox" wire:model="printers.{{ $key }}.drawer" class="h-5 w-5">
                            <span>{{ $w('fields.printer_drawer') }}</span>
                        </label>
                        @if (isset($tested[$key]))
                            <p class="rounded-xl px-4 py-3 text-sm {{ $tested[$key]['ok'] ? 'bg-emerald-500/15 text-emerald-200' : 'bg-rose-500/15 text-rose-300' }}" role="status">{{ $tested[$key]['message'] }}</p>
                        @endif
                        <div class="flex flex-wrap gap-3">
                            <button type="button" wire:click="testPrinter(@js($key))" wire:loading.attr="disabled" wire:target="testPrinter(@js($key))" class="{{ $primary }}">
                                <span wire:loading.remove wire:target="testPrinter(@js($key))">{{ $w('printers.test') }}</span>
                                <span wire:loading wire:target="testPrinter(@js($key))">{{ $w('printers.testing') }}</span>
                            </button>
                            <button type="button" wire:click="removePrinter(@js($key))" class="{{ $secondary }}">{{ $w('printers.remove') }}</button>
                        </div>
                    </div>
                @endforeach

                <button type="button" wire:click="addPrinter" class="{{ $secondary }} self-start" @disabled(count($printers) >= 12)>{{ $w('printers.add_by_hand') }}</button>
                <p class="text-sm text-slate-400">{{ $w('printers.skip_help') }}</p>
                @break

            @case('data')
                <h2 class="text-xl font-bold">{{ $w('steps.data') }}</h2>
                <p class="text-sm text-slate-400">{{ $w('data.help') }}</p>
                @foreach (['menu' => 'menuFile', 'staff' => 'staffFile'] as $type => $field)
                    <div class="flex flex-col gap-2 rounded-2xl bg-slate-800 p-4">
                        <span class="font-semibold">{{ $w('fields.'.$type.'_file') }}</span>
                        <a href="{{ route('setup.template', $type) }}" class="text-sm font-semibold text-emerald-400 underline">{{ $w('data.template', ['type' => $w('fields.'.$type.'_file')]) }}</a>
                        <input type="file" wire:model="{{ $field }}" accept=".xlsx,.xls,.csv" class="text-sm">
                        <span wire:loading wire:target="{{ $field }}" class="text-sm text-slate-400">{{ $w('data.uploading') }}</span>
                        @if ($this->{$field}) <span class="text-sm text-emerald-300">{{ $w('data.ready', ['name' => $this->{$field}->getClientOriginalName()]) }}</span> @endif
                        @error($field) <span class="text-sm text-rose-300">{{ $message }}</span> @enderror
                    </div>
                @endforeach
                <p class="text-sm text-slate-400">{{ $w('data.skip_help') }}</p>
                @break

            @case('backup')
                <h2 class="text-xl font-bold">{{ $w('steps.backup') }}</h2>
                <p class="text-slate-300">{{ $w('backup.body') }}</p>
                <p class="select-all break-all rounded-2xl bg-slate-800 px-4 py-4 text-center font-mono text-2xl tracking-wider text-emerald-300">{{ $backupPassword }}</p>
                <label class="flex min-h-12 items-center gap-3 rounded-xl bg-slate-800 px-4">
                    <input type="checkbox" wire:model="backupWritten" class="h-5 w-5">
                    <span>{{ $w('fields.backup_written') }}</span>
                </label>
                @error('backupWritten') <span class="text-sm text-rose-300">{{ $message }}</span> @enderror
                <label class="{{ $label }}">{{ $w('fields.backup_check') }}
                    <input type="text" wire:model="backupCheck" maxlength="4" autocomplete="off" class="{{ $input }} font-mono tracking-widest">
                    @error('backupCheck') <span class="mt-1 block text-sm text-rose-300">{{ $message }}</span> @enderror
                </label>
                @break

            @case('review')
                <h2 class="text-xl font-bold">{{ $w('steps.review') }}</h2>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt class="text-slate-400">{{ $w('fields.restaurant') }}</dt><dd>{{ $restaurant }}</dd>
                    <dt class="text-slate-400">{{ $w('fields.branch') }}</dt><dd>{{ $branch }}</dd>
                    <dt class="text-slate-400">{{ $w('fields.tin') }}</dt><dd>{{ $tin ?: '—' }}</dd>
                    <dt class="text-slate-400">{{ $w('fields.owner_name') }}</dt><dd>{{ $ownerName }} · {{ $email }}</dd>
                    <dt class="text-slate-400">{{ $w('steps.taxes') }}</dt>
                    <dd>{{ collect($rates)->map(fn ($r, $c) => ($taxNames[$c] ?? $c).' '.$r.' %')->implode(', ') }} · {{ $w('taxes.inclusive_'.($pricesIncludeTax ?: 'no')) }}</dd>
                    <dt class="text-slate-400">{{ $w('taxes.cost_short') }}</dt>
                    <dd>{{ \App\Modules\Inventory\Enums\CostMethod::tryFrom($costMethod)?->label() }}</dd>
                    <dt class="text-slate-400">{{ $w('review.tills') }}</dt><dd>{{ implode(', ', $tills) }}</dd>
                    <dt class="text-slate-400">{{ $w('tills.stations_heading') }}</dt><dd>{{ implode(', ', $stations) }}</dd>
                    <dt class="text-slate-400">{{ $w('steps.printers') }}</dt>
                    <dd>
                        @forelse ($printers as $printer)
                            <span class="block">{{ $printer['name'] }} ({{ $printer['address'] }}): {{ collect($printer['tills'])->map(fn ($i) => $tills[(int) $i] ?? null)->merge(collect($printer['stations'])->map(fn ($i) => $stations[(int) $i] ?? null))->filter()->implode(', ') ?: '—' }}</span>
                        @empty
                            {{ $w('review.no_printers') }}
                        @endforelse
                    </dd>
                    <dt class="text-slate-400">{{ $w('steps.data') }}</dt>
                    <dd>{{ collect(['menu' => $menuFile, 'staff' => $staffFile])->filter()->map(fn ($f) => $f->getClientOriginalName())->implode(', ') ?: $w('review.no_files') }}</dd>
                </dl>
                <p class="text-sm text-slate-400">{{ $w('review.help') }}</p>
                @break

            @case('done')
                <h2 class="text-xl font-bold">{{ $w('done.heading', ['restaurant' => $restaurant]) }}</h2>
                <p class="text-slate-300">{{ $w('done.body') }}</p>
                @foreach ($results as $type => $result)
                    @if ($type === 'problem')
                        <p class="rounded-xl bg-amber-500/15 px-4 py-3 text-sm text-amber-200">{{ $w('done.import_problem', ['problem' => $result]) }}</p>
                    @else
                        <p class="rounded-xl {{ $result['errors'] ? 'bg-amber-500/15 text-amber-200' : 'bg-emerald-500/15 text-emerald-200' }} px-4 py-3 text-sm">
                            {{ $w('fields.'.$type.'_file') }}: {{ $result['summary'] }}@if ($result['errors']) — {{ $w('done.import_fix') }}@endif
                        </p>
                    @endif
                @endforeach
                <div class="flex flex-wrap gap-3">
                    <a href="{{ url(config('rms.admin_path', 'admin')) }}" class="{{ $primary }} inline-flex items-center">{{ $w('done.back_office') }}</a>
                    <a href="{{ route('pos.login') }}" class="{{ $secondary }} inline-flex items-center">{{ $w('done.till') }}</a>
                </div>
                <p class="text-sm text-slate-400">{{ $w('done.next') }}</p>
                @break
        @endswitch
    </section>

    @if (! in_array($step, ['welcome', 'done'], true))
        <nav class="flex justify-between gap-3">
            <button type="button" wire:click="back" class="{{ $secondary }}" @disabled($position <= 1)>{{ $w('back') }}</button>
            @if ($step === 'review')
                <button type="button" wire:click="finish" wire:loading.attr="disabled" class="{{ $primary }}">
                    <span wire:loading.remove wire:target="finish">{{ $w('finish') }}</span>
                    <span wire:loading wire:target="finish">{{ $w('finishing') }}</span>
                </button>
            @else
                <button type="button" wire:click="next" wire:loading.attr="disabled" class="{{ $primary }}">{{ $w('next') }}</button>
            @endif
        </nav>
    @endif
</div>
