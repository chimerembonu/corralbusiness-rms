<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Gateways\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use InvalidArgumentException;

/**
 * Picks the payment provider for each purpose (till MoMo prompt, online payments). The owner chooses a
 * default per purpose on Settings → Payment providers; switching is a setting, never a code change.
 * If the default is not ready (not set up, failed its test, or cannot do that purpose), the first ready
 * provider that can is used, so the till keeps working.
 */
class GatewayManager
{
    public const DEMO = 'demo';

    public function __construct(private readonly ConnectionService $connections, private readonly SettingsService $settings) {}

    /** @return array<int, string> every provider in config/payments.php */
    public function keys(): array
    {
        return array_keys(config('payments.gateways', []));
    }

    /** A provider loaded with this restaurant's details. */
    public function make(string $key): PaymentGateway
    {
        $definition = config("payments.gateways.{$key}") ?? throw new InvalidArgumentException("Unknown payment provider {$key}");
        /** @var PaymentGateway $gateway */
        $gateway = app($definition['class']);

        return $gateway->configure($this->connections->values($definition['connection']));
    }

    public function isReady(string $key): bool
    {
        $connection = config("payments.gateways.{$key}.connection");

        return $connection !== null && $this->connections->isReady($connection);
    }

    /** @return array<int, string> providers set up and tested */
    public function ready(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key) => $this->isReady($key)));
    }

    public static function capabilityFor(string $purpose): GatewayCapability
    {
        return GatewayCapability::from(config("payments.purposes.{$purpose}") ?? throw new InvalidArgumentException("Unknown payment purpose {$purpose}"));
    }

    /**
     * Ready providers that can serve this purpose. Training-mode orders may only use the Demo provider,
     * and real orders may never use it, so practice can never move real money and real sales are never faked.
     *
     * @return array<int, string>
     */
    public function readyFor(string $purpose, bool $training = false): array
    {
        $capability = self::capabilityFor($purpose);

        return array_values(array_filter($this->ready(), fn (string $key) => ($key === self::DEMO) === $training && $this->make($key)->supports($capability)));
    }

    public function defaultFor(string $purpose): ?string
    {
        return $this->settings->get("payments.default.{$purpose}");
    }

    public function setDefault(string $purpose, ?string $key, ?User $by = null): void
    {
        self::capabilityFor($purpose);
        if ($key !== null && ! in_array($key, $this->keys(), true)) {
            throw new InvalidArgumentException("Unknown payment provider {$key}");
        }
        $this->settings->set("payments.default.{$purpose}", $key, tenantWide: true);
        activity('settings')->event('payment_default_changed')->causedBy($by)->withProperties(['purpose' => $purpose, 'provider' => $key])->log('payment_default_changed');
    }

    /** The provider to use now for this purpose, or null when none is ready. */
    public function forPurpose(string $purpose, bool $training = false): ?PaymentGateway
    {
        $candidates = $this->readyFor($purpose, $training);
        if ($candidates === []) {
            return null;
        }
        $default = $this->defaultFor($purpose);
        $key = in_array($default, $candidates, true) ? $default : $candidates[0];

        return $this->make($key);
    }
}
