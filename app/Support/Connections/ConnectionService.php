<?php

namespace App\Support\Connections;

use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Outside services filled in at installation (config/connections.php): payment providers, the online
 * service, SMS, WhatsApp, e-VAT. Stored per restaurant in the settings table under "connection.<name>".
 * Secret fields are encrypted with the server's key and never returned to a screen in full.
 */
class ConnectionService
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return config('connections.connections', []);
    }

    public function definition(string $name): array
    {
        return $this->definitions()[$name] ?? throw new ConnectionException("Unknown connection {$name}");
    }

    public function isConfigured(string $name): bool
    {
        return ($this->stored($name)['values'] ?? []) !== [];
    }

    /** Set up, and its last test passed. Only then can features that need it be switched on. */
    public function isReady(string $name): bool
    {
        $stored = $this->stored($name);

        return ($stored['values'] ?? []) !== [] && ($stored['test']['ok'] ?? false) === true;
    }

    /** @return array{ok: bool, message: string, at: string}|null */
    public function lastTest(string $name): ?array
    {
        return $this->stored($name)['test'] ?? null;
    }

    /** For adapters (payment providers etc.): the details with secrets decrypted. Never send this to a screen. */
    public function values(string $name): array
    {
        $definition = $this->definition($name);
        $values = [];
        foreach ($this->stored($name)['values'] ?? [] as $field => $value) {
            $values[$field] = ($definition['fields'][$field]['secret'] ?? false) && $value !== null ? $this->decrypt($value) : $value;
        }

        return $values;
    }

    /** For screens: secrets shown as •••• plus their last 4 characters. */
    public function masked(string $name): array
    {
        $definition = $this->definition($name);
        $masked = [];
        foreach ($this->values($name) as $field => $value) {
            $masked[$field] = ($definition['fields'][$field]['secret'] ?? false) && filled($value) ? '••••'.substr((string) $value, -4) : $value;
        }

        return $masked;
    }

    /**
     * Save the details typed in. A blank secret keeps the one already saved. Saving clears the last test,
     * so features that need this connection stay off until it is tested again.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(string $name, array $input, ?User $by = null): void
    {
        $definition = $this->definition($name);
        $current = $this->values($name);
        $values = [];
        foreach ($definition['fields'] as $field => $spec) {
            $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
            if ($value === '' && ($spec['secret'] ?? false)) {
                $value = (string) ($current[$field] ?? ''); // blank secret = keep
            }
            if ($value === '' && ($spec['required'] ?? false)) {
                throw new ConnectionException(__('settings::settings.connections.errors.required', ['field' => __("settings::settings.connections.fields.{$field}")]));
            }
            if ($value !== '' && isset($spec['options']) && ! in_array($value, $spec['options'], true)) {
                throw new ConnectionException(__('settings::settings.connections.errors.option', ['field' => __("settings::settings.connections.fields.{$field}")]));
            }
            if ($value !== '' && ($spec['type'] ?? '') === 'url' && ! filter_var($value, FILTER_VALIDATE_URL)) {
                throw new ConnectionException(__('settings::settings.connections.errors.url'));
            }
            if (isset($spec['max']) && mb_strlen($value) > $spec['max']) {
                throw new ConnectionException(__('settings::settings.connections.errors.too_long', ['field' => __("settings::settings.connections.fields.{$field}"), 'max' => $spec['max']]));
            }
            $values[$field] = $value === '' ? null : (($spec['secret'] ?? false) ? Crypt::encryptString($value) : $value);
        }

        $changed = array_keys(array_filter($definition['fields'], fn ($spec, $field) => ($current[$field] ?? null) !== ($input[$field] ?? null) && filled($input[$field] ?? null), ARRAY_FILTER_USE_BOTH));
        $this->settings->set("connection.{$name}", ['values' => $values, 'test' => null], tenantWide: true);
        activity('settings')->event('connection_saved')->causedBy($by)->withProperties(['connection' => $name, 'fields' => $changed])->log('connection_saved');
    }

    /** Run the connection's test against the real service and remember the result. */
    public function test(string $name, ?User $by = null): TestResult
    {
        $definition = $this->definition($name);
        if (! $this->isConfigured($name)) {
            return TestResult::failed(__('settings::settings.connections.not_set_up'));
        }

        $tester = $definition['tester'] ?? null;
        try {
            $result = $tester
                ? app($tester)->test($this->values($name))
                : TestResult::failed(__('settings::settings.connections.not_built', ['stage' => $definition['stage'] ?? '']));
        } catch (Throwable $e) {
            report($e);
            $result = TestResult::failed(__('settings::settings.connections.test_crashed'));
        }

        $stored = $this->stored($name);
        $stored['test'] = ['ok' => $result->ok, 'message' => $result->message, 'at' => now()->toIso8601String()];
        $this->settings->set("connection.{$name}", $stored, tenantWide: true);
        activity('settings')->event('connection_tested')->causedBy($by)->withProperties(['connection' => $name, 'ok' => $result->ok])->log('connection_tested');

        return $result;
    }

    public function remove(string $name, ?User $by = null): void
    {
        $this->definition($name);
        $this->settings->forget("connection.{$name}", tenantWide: true);
        activity('settings')->event('connection_removed')->causedBy($by)->withProperties(['connection' => $name])->log('connection_removed');
    }

    /** A feature "need" (e.g. payment_provider) is met when any connection that can serve it is ready. */
    public function needMet(string $need): bool
    {
        foreach (config("features.needs.{$need}", []) as $name) {
            if ($this->isReady($name)) {
                return true;
            }
        }

        return false;
    }

    private function stored(string $name): array
    {
        return (array) ($this->settings->get("connection.{$name}") ?? []);
    }

    private function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null; // the server's key changed: the owner types the secret in again
        }
    }
}
