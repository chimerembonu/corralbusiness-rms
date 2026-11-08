@php($d = $this->data())
@php($c = fn (string $k) => __('payments::payments.matching.columns.'.$k))
@php($g = fn (string $k) => __('payments::payments.matching.groups.'.$k))
@php($t = fn (string $k, array $r = []) => __('payments::payments.matching.'.$k, $r))
@php($m = fn (int $p) => money($p))
@php($fetching = collect($d['statements'])->contains(fn ($s) => ($s['status'] ?? null) === 'fetching'))
<x-filament-panels::page>
    @include('reports::filament.partials.filters')
    <style>
        .pm-tag { display: inline-block; font-size: .72rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
        .pm-needs_manager, .pm-missing { background: rgba(244,63,94,.15); color: #e11d48; }
        .pm-matched { background: rgba(16,185,129,.15); color: #059669; }
        .pm-refunded { background: rgba(14,165,233,.15); color: #0284c7; }
        .pm-waiting { background: rgba(245,158,11,.15); color: #b45309; }
        .pm-not_paid { background: rgba(128,128,128,.15); }
        .pm-kpi-alert { border-color: rgba(244,63,94,.6) !important; }
        .pm-note { font-size: .82rem; opacity: .85; }
        .pm-statements { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
        .pm-statement { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 10px 12px; font-size: .9rem; }
    </style>

    {{-- The provider's own list: what it says, when it was fetched, and the button to (re)fetch it. --}}
    <div class="pm-statements" @if ($fetching) wire:poll.3s @endif>
        @foreach ($d['statements'] as $gateway => $s)
            <div class="pm-statement" wire:key="statement-{{ $gateway }}">
                <span style="flex: 1; min-width: 220px">
                    @switch($s['status'])
                        @case('ready') {{ trans_choice('payments::payments.matching.fetched', $s['count'], ['provider' => $s['name'], 'time' => \Carbon\CarbonImmutable::parse($s['fetched_at'])->setTimezone(config('app.timezone'))->format('d/m H:i'), 'count' => $s['count']]) }} @break
                        @case('fetching') {{ $t('fetching', ['provider' => $s['name']]) }} @break
                        @case('failed') <span style="color:#e11d48">{{ $s['error'] }}</span> @break
                        @case('unsupported') {{ $t('unsupported', ['provider' => $s['name']]) }} @break
                        @default {{ $t('not_fetched', ['provider' => $s['name']]) }}
                    @endswitch
                </span>
                @if ($s['status'] !== 'unsupported')
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="compare(@js($gateway))" :disabled="$s['status'] === 'fetching'">{{ $t('compare', ['provider' => $s['name']]) }}</x-filament::button>
                @endif
            </div>
        @endforeach
    </div>

    <div class="rp-kpis">
        @foreach (\App\Modules\Payments\Gateways\GatewayReconciliationService::GROUPS as $group)
            <div class="rp-kpi {{ in_array($group, ['needs_manager', 'missing'], true) && $d['groups'][$group]['count'] > 0 ? 'pm-kpi-alert' : '' }}">
                <span>{{ $g($group) }}</span><strong>{{ $d['groups'][$group]['count'] }}</strong> <small style="opacity:.6">· {{ $m($d['groups'][$group]['amount']) }}</small>
            </div>
        @endforeach
    </div>
    <div class="rp-kpis">
        <div class="rp-kpi"><span>{{ $t('totals.asked') }}</span><strong>{{ $m($d['totals']['asked']) }}</strong></div>
        <div class="rp-kpi"><span>{{ $t('totals.till') }}</span><strong>{{ $m($d['totals']['till']) }}</strong></div>
        <div class="rp-kpi"><span>{{ $t('totals.provider') }}</span><strong>{{ $d['totals']['provider'] === null ? '—' : $m($d['totals']['provider']) }}</strong></div>
    </div>

    @if ($d['missing'] !== [])
        <x-filament::section :heading="$t('missing_heading')">
            <table class="rp-table"><thead><tr><th>{{ $c('when') }}</th><th>{{ $c('provider') }}</th><th>{{ $c('reference') }}</th><th class="r">{{ $c('asked') }}</th><th>{{ $c('note') }}</th></tr></thead>
                <tbody>@foreach ($d['missing'] as $r)<tr><td>{{ $r['when'] }}</td><td>{{ __('payments::payments.gateway.names.'.$r['gateway']) }}</td><td>{{ $r['reference'] }}</td><td class="r">{{ $m($r['amount']) }}</td><td class="pm-note">{{ $r['note'] }}</td></tr>@endforeach</tbody></table>
        </x-filament::section>
    @endif

    <x-filament::section :heading="$t('payments')">
        @if ($d['rows'] === [])
            <p class="text-sm text-gray-500">{{ $t('none') }}</p>
        @else
            <table class="rp-table">
                <thead><tr><th>{{ $c('group') }}</th><th>{{ $c('when') }}</th><th>{{ $c('order') }}</th><th>{{ $c('provider') }}</th><th>{{ $c('phone') }}</th><th>{{ $c('reference') }}</th><th class="r">{{ $c('asked') }}</th><th class="r">{{ $c('till') }}</th><th>{{ $c('provider_says') }}</th><th>{{ $c('note') }}</th></tr></thead>
                <tbody>
                    @foreach ($this->sorted($d['rows']) as $r)
                        <tr>
                            <td><span class="pm-tag pm-{{ $r['group'] }}">{{ $g($r['group']) }}</span></td>
                            <td>{{ $r['when'] }}</td><td>{{ $r['order'] }}</td><td>{{ $r['provider'] }} · {{ $r['network'] }}</td><td>{{ $r['phone'] }}</td>
                            <td style="font-family: monospace; font-size: .75rem; word-break: break-all; max-width: 14rem">{{ $r['reference'] }}</td>
                            <td class="r">{{ $m($r['asked']) }}</td><td class="r">{{ $r['till'] ? $m($r['till']) : '—' }}</td>
                            <td>{{ $r['provider_label'] }}</td><td class="pm-note">{{ $r['note'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
