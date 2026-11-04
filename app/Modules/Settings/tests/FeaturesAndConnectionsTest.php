<?php

use App\Modules\Settings\Filament\Pages\ConnectionsPage;
use App\Modules\Settings\Filament\Pages\FeaturesPage;
use App\Modules\Settings\Models\Setting;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;
use App\Support\Connections\ConnectionException;
use App\Support\Connections\ConnectionService;
use App\Support\Features\FeatureException;
use App\Support\Features\FeatureService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Fakes\FakeConnectionTester;

/*
 * Complete Edition E1: features start off and switch on only when built, licensed and their connections
 * are set up and tested. Connection secrets are stored locked and never shown or logged in full.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Owner']);
    $this->owner->assignRole(Role::Owner->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Manager']);
    $this->manager->assignRole(Role::Manager->value);
    $this->connections = app(ConnectionService::class);
    $this->features = app(FeatureService::class);
    config(['connections.connections.paystack.tester' => FakeConnectionTester::class]);
});

it('stores secrets locked, shows only their last 4 characters and keeps them when left blank', function () {
    $this->connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_good_0000'], $this->owner);

    $raw = json_encode(Setting::query()->where('key', 'connection.paystack')->sole()->value);
    expect($raw)->not->toContain('sk_test_good_0000')->and($raw)->toContain('pk_test_abc')
        ->and($this->connections->values('paystack')['secret_key'])->toBe('sk_test_good_0000')
        ->and($this->connections->masked('paystack')['secret_key'])->toBe('••••0000');

    $this->connections->save('paystack', ['mode' => 'live', 'public_key' => 'pk_live_xyz', 'secret_key' => ''], $this->owner);
    expect($this->connections->values('paystack'))->toMatchArray(['mode' => 'live', 'public_key' => 'pk_live_xyz', 'secret_key' => 'sk_test_good_0000'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('sk_test_good_0000');
});

it('refuses missing or wrong details in plain words', function () {
    expect(fn () => $this->connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk'], $this->owner))->toThrow(ConnectionException::class, 'Secret key is needed')
        ->and(fn () => $this->connections->save('paystack', ['mode' => 'maybe', 'public_key' => 'pk', 'secret_key' => 's'], $this->owner))->toThrow(ConnectionException::class)
        ->and(fn () => $this->connections->save('own_server', ['url' => 'not a url', 'token' => 't'], $this->owner))->toThrow(ConnectionException::class, 'web address')
        ->and(fn () => $this->connections->save('sms', ['sender_id' => 'MuchTooLongSender', 'api_url' => 'https://sms.example', 'api_key' => 'k'], $this->owner))->toThrow(ConnectionException::class, 'at most 11');
});

it('is ready only after a passing test, and saving again needs a new test', function () {
    $this->connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk', 'secret_key' => 'sk_test_bad'], $this->owner);
    expect($this->connections->test('paystack', $this->owner)->ok)->toBeFalse()->and($this->connections->isReady('paystack'))->toBeFalse();

    $this->connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk', 'secret_key' => 'sk_test_good_0000'], $this->owner);
    expect($this->connections->test('paystack', $this->owner)->ok)->toBeTrue()
        ->and($this->connections->isReady('paystack'))->toBeTrue()
        ->and($this->connections->needMet('payment_provider'))->toBeTrue();

    $this->connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk2', 'secret_key' => ''], $this->owner);
    expect($this->connections->isReady('paystack'))->toBeFalse();
});

it('says when a connection is not built yet instead of pretending it works', function () {
    $this->connections->save('payswitch', ['mode' => 'test', 'merchant_id' => 'TTM-1', 'api_user' => 'u', 'api_key' => 'k'], $this->owner);
    $result = $this->connections->test('payswitch', $this->owner);
    expect($result->ok)->toBeFalse()->and($result->message)->toContain('stage E2');
});

it('keeps every feature off until it is built, licensed and its connections are tested', function () {
    foreach (array_keys($this->features->definitions()) as $key) {
        expect($this->features->isOn($key))->toBeFalse();
    }
    expect(fn () => $this->features->set('momo_prompt', true, $this->owner))->toThrow(FeatureException::class, 'Not available yet');

    // Pretend the stage is built: now only the missing payment provider stops it.
    config(['features.features.momo_prompt.available' => true]);
    expect($this->features->blockers('momo_prompt'))->toHaveCount(1)
        ->and(fn () => $this->features->set('momo_prompt', true, $this->owner))->toThrow(FeatureException::class, 'payment provider');

    $this->connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk', 'secret_key' => 'sk_test_good_0000'], $this->owner);
    $this->connections->test('paystack', $this->owner);
    $this->features->set('momo_prompt', true, $this->owner);
    expect($this->features->isOn('momo_prompt'))->toBeTrue();

    // If the keys are changed and not yet tested, the feature reports off again (switch stays on).
    $this->connections->save('paystack', ['mode' => 'live', 'public_key' => 'pk_live', 'secret_key' => ''], $this->owner);
    expect($this->features->switchedOn('momo_prompt'))->toBeTrue()->and($this->features->isOn('momo_prompt'))->toBeFalse();
});

it('lets the owner set up connections and switch features; managers may only look at features', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['features.features.recipes.available' => true]);

    $this->actingAs($this->manager);
    $this->get(ConnectionsPage::getUrl())->assertForbidden();
    Livewire::test(FeaturesPage::class)->assertOk()->assertSee('Recipes & food cost')->assertSee('Only the owner can switch')
        ->call('toggle', 'recipes')->assertForbidden();
    expect($this->features->switchedOn('recipes'))->toBeFalse();

    $this->actingAs($this->owner);
    Livewire::test(FeaturesPage::class)->call('toggle', 'recipes');
    expect($this->features->isOn('recipes'))->toBeTrue();

    Livewire::test(ConnectionsPage::class)->assertOk()->assertSee('Paystack')->assertSee('PaySwitch')
        ->mountAction('configure', ['name' => 'paystack'])
        ->setActionData(['mode' => 'test', 'public_key' => 'pk_test_ui', 'secret_key' => 'sk_test_good_0000'])
        ->callMountedAction()->assertHasNoActionErrors()
        ->call('runTest', 'paystack')
        ->assertSee('••••0000')->assertDontSee('sk_test_good_0000')->assertSee('Working');
    expect($this->connections->isReady('paystack'))->toBeTrue();
});
