{{-- Licence warning at the top of every back-office page, for the owner and managers only (E1.2). --}}
@can(\App\Modules\Staff\Enums\Permission::SettingsManage->value)
    @php($warning = app(\App\Support\Licence\LicenceService::class)->warning())
    @if ($warning)
        <div role="alert" style="margin-bottom:12px;padding:10px 14px;border-radius:10px;border:1px solid rgba(245,158,11,.5);background:rgba(245,158,11,.12);font-size:.9rem;font-weight:600;">
            {{ $warning }}
            <a href="{{ \App\Modules\Settings\Filament\Pages\LicencePage::getUrl(panel: 'admin') }}" style="text-decoration:underline;margin-left:6px;">{{ __('settings::settings.licence.banner.open') }}</a>
        </div>
    @endif
@endcan
