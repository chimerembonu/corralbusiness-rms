<?php

use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Support\Connections\ConnectionService;

/*
 * The rulebook check: EVERY provider in config/payments.php must pass these. A newly added provider
 * that misses a step (no fields, no name, wrong reference format…) fails here before it reaches a till.
 */
// Read straight from the file: datasets are built before the app starts.
dataset('gateways', array_keys((require dirname(__DIR__, 4).'/config/payments.php')['gateways']));

it('follows the payment provider rulebook', function (string $key) {
    $definition = config("payments.gateways.{$key}");
    $gateway = app($definition['class']);

    expect($gateway)->toBeInstanceOf(PaymentGateway::class)
        ->and($gateway->key())->toBe($key)
        ->and($gateway->capabilities())->not->toBeEmpty()->each->toBeInstanceOf(GatewayCapability::class)
        ->and($gateway->networks())->each->toBeInstanceOf(MomoProvider::class)
        ->and($definition['connection'])->toBeString()
        ->and($definition['fields'] ?? [])->not->toBeEmpty()
        // Names and settings show up on screen without extra work.
        ->and(__("payments::payments.gateway.names.{$key}"))->not->toBe("payments::payments.gateway.names.{$key}")
        ->and(__("settings::settings.connections.names.{$definition['connection']}"))->not->toBe("settings::settings.connections.names.{$definition['connection']}")
        ->and(array_key_exists($definition['connection'], app(ConnectionService::class)->definitions()))->toBeTrue();

    foreach (array_keys($definition['fields']) as $field) {
        expect(__("settings::settings.connections.fields.{$field}"))->not->toBe("settings::settings.connections.fields.{$field}");
    }

    // A prompt needs a way to find out whether it was paid: the store server cannot be called from the internet.
    if ($gateway->supports(GatewayCapability::MomoPrompt)) {
        expect($gateway->supports(GatewayCapability::StatusCheck))->toBeTrue()->and($gateway->networks())->not->toBeEmpty();
    }

    $refs = collect(range(1, 200))->map(fn () => $gateway->newReference());
    expect($refs->unique())->toHaveCount(200)->and($refs->every(fn ($r) => strlen($r) <= 40))->toBeTrue();
})->with('gateways');

it('never asks for card numbers in any provider settings', function () {
    foreach (config('payments.gateways') as $definition) {
        expect(array_keys($definition['fields']))->not->toContain('pan', 'card_number', 'cvv', 'expiry');
    }
});
