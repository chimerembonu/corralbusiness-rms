<?php

namespace App\Modules\Payments\Filament\Pages;

use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Gateways\GatewayManager;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Payments\Models\GatewayTransaction;
use App\Modules\Staff\Enums\Role;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Back office → Settings → Payment providers (Complete Edition E2). Owner only. Choose which provider
 * handles each kind of payment — switching is a setting, never a code change — see what each provider
 * can do, and find provider payments that need a manager (paid, but not attached to an order).
 */
class PaymentProvidersPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'payment-providers';

    protected string $view = 'payments::filament.payment-providers';

    /** @var array<string, string|null> purpose → provider key */
    public array $defaults = [];

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
        return __('payments::payments.gateway.page.title');
    }

    public function getTitle(): string
    {
        return __('payments::payments.gateway.page.title');
    }

    public function getSubheading(): ?string
    {
        return __('payments::payments.gateway.page.help');
    }

    public function mount(GatewayManager $gateways): void
    {
        foreach (array_keys(config('payments.purposes')) as $purpose) {
            $this->defaults[$purpose] = $gateways->defaultFor($purpose);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function providers(): array
    {
        $gateways = app(GatewayManager::class);

        return collect($gateways->keys())->map(fn (string $key) => [
            'key' => $key,
            'name' => __("payments::payments.gateway.names.{$key}"),
            'ready' => $gateways->isReady($key),
            'capabilities' => collect($gateways->make($key)->capabilities())->map(fn ($c) => __('payments::payments.gateway.capabilities.'.$c->value))->all(),
            'networks' => collect($gateways->make($key)->networks())->map->label()->all(),
        ])->all();
    }

    /** @return array<string, array<string, mixed>> purpose → label, options (provider → name), in use now */
    public function purposes(): array
    {
        $gateways = app(GatewayManager::class);
        $out = [];
        foreach (array_keys(config('payments.purposes')) as $purpose) {
            $capability = GatewayManager::capabilityFor($purpose);
            $options = [];
            foreach ($gateways->keys() as $key) {
                if ($key !== GatewayManager::DEMO && $gateways->make($key)->supports($capability)) {
                    $options[$key] = __("payments::payments.gateway.names.{$key}").($gateways->isReady($key) ? '' : ' — '.__('payments::payments.gateway.page.not_ready'));
                }
            }
            $out[$purpose] = [
                'label' => __("payments::payments.gateway.page.purposes.{$purpose}"),
                'options' => $options,
                'in_use' => $gateways->forPurpose($purpose)?->key(),
            ];
        }

        return $out;
    }

    public function save(GatewayManager $gateways): void
    {
        abort_unless(self::canAccess(), 403);
        foreach ($this->defaults as $purpose => $key) {
            $gateways->setDefault($purpose, $key ?: null, auth()->user());
        }
        Notification::make()->success()->title(__('payments::payments.gateway.page.saved'))->send();
    }

    /** Provider payments that need a manager, newest first. */
    public function attention()
    {
        return GatewayTransaction::query()->where('status', GatewayStatus::NeedsAttention->value)->with('order')->latest()->limit(50)->get();
    }

    /** Refunds the provider could not send back, waiting for a manager to give the money back by hand (E2.4). */
    public function refundsByHand()
    {
        return GatewayRefund::query()->needsHand()->with(['payment.order', 'transaction'])->latest()->limit(50)->get();
    }
}
