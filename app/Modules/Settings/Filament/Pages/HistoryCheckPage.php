<?php

namespace App\Modules\Settings\Filament\Pages;

use App\Modules\Staff\Enums\Role;
use App\Support\Audit\AuditChain;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Back office → Settings → History check (Complete Edition E9.4): is the audit log as it was written?
 * Shows the nightly check, runs one now, and tests a code printed on an old Z report. Owner only.
 */
class HistoryCheckPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 97;

    protected static ?string $slug = 'history-check';

    protected string $view = 'settings::filament.history-check';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(Role::Owner->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('settings::settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings::settings.history.title');
    }

    public static function getNavigationBadge(): ?string
    {
        $problems = json_decode((string) app(AuditChain::class)->lastCheck()?->problems, true);

        return is_array($problems) && $problems !== [] ? (string) count($problems) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return __('settings::settings.history.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings::settings.history.help');
    }

    /** @return array{checked_at: ?string, checked: ?int, problems: list<string>, code: string} */
    public function status(): array
    {
        $last = app(AuditChain::class)->lastCheck();
        $problems = json_decode((string) $last?->problems, true);

        return [
            'checked_at' => $last?->verified_at ? Carbon::parse($last->verified_at)->format('d/m/Y H:i') : null,
            'checked' => $last?->verified_count === null ? null : (int) $last->verified_count,
            'problems' => array_map(AuditChain::describe(...), is_array($problems) ? $problems : []),
            'code' => AuditChain::formatCode((int) ($last->last_seq ?? 0), (string) ($last->last_hash ?? AuditChain::GENESIS)),
        ];
    }

    protected function getHeaderActions(): array
    {
        $h = fn (string $k, array $r = []) => __('settings::settings.history.'.$k, $r);

        return [
            Action::make('checkNow')->label($h('check_now'))->icon(Heroicon::ShieldCheck)
                ->authorize(fn () => self::canAccess())
                ->action(function (AuditChain $chain) use ($h) {
                    $chain->seal();
                    $result = $chain->check();
                    $result['problems'] === []
                        ? Notification::make()->success()->title($h('all_good', ['count' => $result['checked']]))->send()
                        : Notification::make()->danger()->title(trans_choice('settings::settings.history.problems_found', count($result['problems']), ['count' => count($result['problems'])]))->send();
                }),
            Action::make('testCode')->label($h('test_code'))->icon(Heroicon::DocumentMagnifyingGlass)->color('gray')
                ->authorize(fn () => self::canAccess())
                ->modalDescription($h('test_code_help'))
                ->schema([TextInput::make('code')->label($h('code'))->placeholder('1234-0A1B2C3D4E')->required()->maxLength(40)])
                ->action(function (array $data, AuditChain $chain) use ($h) {
                    $chain->seal();
                    match ($chain->codeMatches((string) $data['code'])) {
                        true => Notification::make()->success()->title($h('code_ok', ['code' => $data['code']]))->send(),
                        false => Notification::make()->danger()->title($h('code_bad', ['code' => $data['code']]))->persistent()->send(),
                        null => Notification::make()->warning()->title($h('code_unreadable'))->send(),
                    };
                }),
        ];
    }
}
