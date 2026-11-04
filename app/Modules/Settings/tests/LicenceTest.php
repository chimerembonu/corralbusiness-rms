<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Settings\Filament\Pages\LicencePage;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Features\FeatureException;
use App\Support\Features\FeatureService;
use App\Support\Licence\EnforceLicence;
use App\Support\Licence\LicenceCodec;
use App\Support\Licence\LicenceException;
use App\Support\Licence\LicenceService;
use App\Support\Licence\LicenceStatus;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakeConnectionTester;

/*
 * Complete Edition E1.2 — licence and plans, checked without internet.
 * Timeline used below: a licence valid until 31 January 2027, 14 grace days (1–14 February),
 * reports-only from 04:00 on 15 February.
 */

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Owner']);
    $this->owner->assignRole(Role::Owner->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Manager']);
    $this->manager->assignRole(Role::Manager->value);
    $this->licence = app(LicenceService::class);
    $this->orders = app(OrderService::class);
    Carbon::setTestNow('2026-12-01 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function endingJanuary(array $details = []): string
{
    return testLicenceCode(['issued' => '2026-11-04', 'expires' => '2027-01-31'] + $details);
}

it('reads a genuine code, even pasted with line breaks, and rejects changed, foreign or broken codes', function () {
    $code = endingJanuary(['plan' => 'business', 'branches' => 3, 'extras' => ['payroll']]);

    $licence = $this->licence->check(chunk_split($code, 40, "\n "));
    expect($licence->plan)->toBe('business')->and($licence->branches)->toBe(3)
        ->and($licence->expires->toDateString())->toBe('2027-01-31')->and($licence->extras)->toBe(['payroll']);

    // Someone edits the details (e.g. plan or end date) inside a genuine code: the signature no longer matches.
    [$prefix, $body, $sig] = explode('.', $code);
    $json = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    $forged = $prefix.'.'.rtrim(strtr(base64_encode(json_encode(['expires' => '2099-12-31'] + $json)), '+/', '-_'), '=').'.'.$sig;
    expect(fn () => $this->licence->check($forged))->toThrow(LicenceException::class, 'not issued by Corral Systems');

    // Signed with a key that is not Corral's.
    $other = sodium_crypto_sign_keypair();
    $fake = LicenceCodec::issue($json, base64_encode(sodium_crypto_sign_secretkey($other)), 'test');
    expect(fn () => $this->licence->check($fake))->toThrow(LicenceException::class, 'not issued by Corral Systems')
        ->and(fn () => $this->licence->check(substr($code, 0, 50)))->toThrow(LicenceException::class, 'not complete')
        ->and(fn () => $this->licence->check('hello'))->toThrow(LicenceException::class, 'not complete')
        ->and(fn () => $this->licence->check(endingJanuary(['plan' => 'platinum'])))->toThrow(LicenceException::class, 'missing details')
        ->and(fn () => $this->licence->check(endingJanuary(['tenant' => 'someone-else', 'restaurant' => 'Other Place'])))->toThrow(LicenceException::class, 'another restaurant (Other Place)');

    // A code tied to THIS restaurant is accepted.
    expect($this->licence->check(endingJanuary(['tenant' => $this->branch->tenant_id]))->tenantId)->toBe($this->branch->tenant_id);
});

it('trusts only Corral Systems\' own public key in the shipped settings', function () {
    $code = endingJanuary();
    config(['licence.public_keys' => (require config_path('licence.php'))['public_keys']]);

    expect(strlen((string) base64_decode(config('licence.public_keys.corral-2026'), true)))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
        ->and(fn () => $this->licence->check($code))->toThrow(LicenceException::class, 'not issued by Corral Systems');
});

it('is active until the end date, then 14 grace days, then reports-only from 4 in the morning', function () {
    $this->licence->install(endingJanuary(), $this->owner);

    $at = function (string $when) {
        Carbon::setTestNow($when);

        return $this->licence->status();
    };
    expect($at('2027-01-31 23:59:59'))->toBe(LicenceStatus::Active)
        ->and($at('2027-02-01 00:00:00'))->toBe(LicenceStatus::Grace)
        ->and($at('2027-02-14 22:00:00'))->toBe(LicenceStatus::Grace)
        ->and($at('2027-02-15 03:59:59'))->toBe(LicenceStatus::Grace)   // a late service is never cut off
        ->and($at('2027-02-15 04:00:00'))->toBe(LicenceStatus::ReadOnly);

    // A server clock set far back does not make an ended licence look new: it never counts before the issue day.
    Carbon::setTestNow('2020-01-01 12:00:00');
    expect($this->licence->status())->toBe(LicenceStatus::Active);

    expect(Activity::query()->where('event', 'licence_installed')->sole()->properties['plan'])->toBe('pro');
});

it('warns the owner 30 days before the end, during the grace period, and when reports-only', function () {
    $this->licence->install(endingJanuary(), $this->owner);

    expect($this->licence->warning())->toBeNull();
    Carbon::setTestNow('2027-01-21 09:00');
    expect($this->licence->warning())->toContain('ends on 31 January 2027 (10 days left)');
    Carbon::setTestNow('2027-02-03 09:00');
    expect($this->licence->warning())->toContain('Everything still works until 14 February 2027');
    Carbon::setTestNow('2027-02-16 09:00');
    expect($this->licence->warning())->toContain('reports-only');
});

it('switches on only features the plan includes, or that were sold on top, and none without a licence', function () {
    config(['payments.gateways.paystack.tester' => FakeConnectionTester::class, 'features.features.recipes.available' => true]);
    $connections = app(ConnectionService::class);
    $connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk', 'secret_key' => 'sk_test_good_0000'], $this->owner);
    $connections->test('paystack', $this->owner);
    $features = app(FeatureService::class);

    // No licence: the till works, but extra features stay off.
    expect($features->blockers('momo_prompt'))->toBe(['Needs a licence from Corral Systems (Pro plan or higher), in Settings → Licence.']);

    // Starter does not include MoMo prompts (a Pro feature)...
    $this->licence->install(endingJanuary(['plan' => 'starter']), $this->owner);
    expect(fn () => $features->set('momo_prompt', true, $this->owner))->toThrow(FeatureException::class, 'does not include it (needs Pro)');

    // ...unless it was sold on top of the plan.
    $this->licence->install(endingJanuary(['plan' => 'starter', 'extras' => ['momo_prompt']]), $this->owner);
    $features->set('momo_prompt', true, $this->owner);
    expect($features->isOn('momo_prompt'))->toBeTrue()->and($features->blockers('recipes'))->not->toBe([]);

    // Pro includes recipes; Business includes everything.
    $this->licence->install(endingJanuary(['plan' => 'pro']), $this->owner);
    expect($features->blockers('recipes'))->toBe([])->and($features->licensed('payroll'))->toBeFalse();
    $this->licence->install(endingJanuary(['plan' => 'business']), $this->owner);
    expect($features->licensed('payroll'))->toBeTrue()->and($features->licensed('whatsapp'))->toBeTrue();

    // During the grace period features keep working; once reports-only they go off by themselves.
    Carbon::setTestNow('2027-02-10 12:00');
    expect($features->isOn('momo_prompt'))->toBeTrue();
    Carbon::setTestNow('2027-02-15 04:00');
    expect($features->isOn('momo_prompt'))->toBeFalse()
        ->and($features->blockers('momo_prompt'))->toContain('Your licence has ended. Renew it in Settings → Licence.');
});

it('never stops a till mid-service: open orders are finished and paid, only new orders wait for a renewal', function () {
    $this->licence->install(endingJanuary(), $this->owner);
    $till = Till::create(['name' => 'Till 1', 'code' => 'T1']);

    // Last night of the grace period: service runs past midnight as usual.
    Carbon::setTestNow('2027-02-15 01:30');
    app(CashService::class)->open($till, $this->owner, Money::of(5000), $this->orders->businessDate());
    $order = $this->orders->open(OrderType::Takeaway, $this->owner);
    $this->orders->addItem($order, $this->menu['coke'], null, 1, [], null, $this->owner);

    // 04:00: reports-only begins. The open order can still get items, be sent and be paid...
    Carbon::setTestNow('2027-02-15 04:05');
    $this->orders->addItem($order, $this->menu['water'], null, 1, [], null, $this->owner);
    $this->orders->sendToKitchen($order->fresh(), $this->owner);
    app(PaymentService::class)->payCash($order->fresh(), Money::of(100000), $this->owner);
    expect($order->fresh()->balanceDue()->isZero())->toBeTrue();

    // ...but a new order is refused, in plain words.
    expect(fn () => $this->orders->open(OrderType::Takeaway, $this->owner))
        ->toThrow(LicenceException::class, 'New orders are paused');

    // Entering a renewed code lets the till carry on at once.
    $this->licence->install(testLicenceCode(['issued' => '2027-02-15', 'expires' => '2028-01-31']), $this->owner);
    expect($this->orders->open(OrderType::Takeaway, $this->owner)->exists)->toBeTrue();
});

it('makes the back office reports-only after the grace period, keeping reports and the licence page open', function () {
    $this->licence->install(endingJanuary(), $this->owner);
    $this->actingAs($this->owner);

    Carbon::setTestNow('2027-02-10 12:00'); // grace: everything opens, with the warning
    $this->get('/admin/items')->assertOk()->assertSee('Your licence has ended. Everything still works until 14 February 2027');

    Carbon::setTestNow('2027-02-16 12:00');
    $this->get('/admin/items')->assertRedirect('/admin/licence');
    $this->get('/admin/staff/create')->assertRedirect('/admin/licence');
    $this->get('/admin/reports/sales')->assertOk();
    $this->get('/admin/day-closes')->assertOk();
    $this->get('/admin/backups')->assertOk();
    $this->get('/admin/licence')->assertOk()->assertSee('Ended – reports only')->assertSee('tills cannot start new orders');

    // A back-office button pressed on a page left open from before is refused too.
    $request = Request::create('/livewire/update', 'POST');
    $request->headers->set('X-Livewire', '1');
    $request->setRouteResolver(fn () => (new Route('GET', 'admin/items', []))->name('filament.admin.resources.items.index'));
    expect(fn () => app(EnforceLicence::class)->handle($request, fn () => response('saved')))
        ->toThrow(HttpException::class, 'reports-only');
});

it('shows the licence to owner and managers, and lets only the owner enter a code', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::actingAs($this->manager)->test(LicencePage::class)
        ->assertOk()->assertSee('No licence is installed')->assertSee($this->branch->tenant_id)->assertSee('Only the owner can enter a licence code')
        ->set('code', endingJanuary())->call('install')->assertForbidden();
    expect($this->licence->current())->toBeNull();

    Livewire::actingAs($this->owner)->test(LicencePage::class)
        ->set('code', 'CBL1.broken')->call('install')->assertNotified('That licence code was not installed');
    expect($this->licence->current())->toBeNull();

    Livewire::actingAs($this->owner)->test(LicencePage::class)
        ->set('code', endingJanuary(['plan' => 'business', 'branches' => 2, 'restaurant' => 'Auntie Ama\'s']))->call('install')
        ->assertNotified('Licence installed: Business plan, valid until 31 January 2027.')
        ->assertSet('code', '')
        ->assertSee('Auntie Ama')->assertSee('1 in use of 2 allowed')->assertSee('Payroll')->assertSee('Active');
});

it('counts branches against the licence', function () {
    $this->licence->install(endingJanuary(['branches' => 1]), $this->owner);
    expect($this->licence->canAddBranch())->toBeFalse()->and($this->licence->overBranchLimit())->toBeFalse();

    Branch::create(['tenant_id' => $this->branch->tenant_id, 'name' => 'Airport', 'code' => 'AIR']);
    expect($this->licence->overBranchLimit())->toBeTrue()
        ->and($this->licence->warning())->toBe('You have 2 branches but your licence covers 1. Contact Corral Systems.');

    $this->licence->install(endingJanuary(['branches' => 3]), $this->owner);
    expect($this->licence->overBranchLimit())->toBeFalse()->and($this->licence->canAddBranch())->toBeTrue();
});

it('makes keys, issues codes and installs them from the command line', function () {
    $dir = sys_get_temp_dir().'/rms-licence-'.uniqid();
    $keyFile = "{$dir}/signing.key";

    expect(Artisan::call('rms:licence-keys', ['--out' => $keyFile, '--id' => 'test']))->toBe(0);
    preg_match("/'test' => '([^']+)'/", Artisan::output(), $m);
    expect(decoct(fileperms($keyFile) & 0777))->toBe('600')
        ->and(file_get_contents($keyFile))->not->toContain($m[1]); // the file holds the SECRET half only
    expect(Artisan::call('rms:licence-keys', ['--out' => $keyFile]))->toBe(1); // never overwritten
    config(['licence.public_keys' => ['test' => $m[1]]]);

    expect(Artisan::call('rms:licence-issue', [
        '--key' => $keyFile, '--restaurant' => 'Test Chop Bar', '--plan' => 'pro', '--branches' => 2,
        '--expires' => '2027-11-30', '--tenant' => $this->branch->tenant_id, '--extras' => 'payroll',
    ]))->toBe(0);
    preg_match('/CBL1\.\S+/', Artisan::output(), $code);

    $this->artisan('rms:licence', ['--install' => $code[0]])->expectsOutputToContain('Licence installed.')->assertSuccessful();
    expect($this->licence->current()->plan)->toBe('pro')->and($this->licence->current()->extras)->toBe(['payroll'])
        ->and($this->licence->current()->expires->toDateString())->toBe('2027-11-30');

    expect(Artisan::call('rms:licence-issue', ['--key' => $keyFile, '--restaurant' => 'X', '--plan' => 'gold', '--expires' => '2027-01-01']))->toBe(1)
        ->and(Artisan::call('rms:licence-issue', ['--key' => $keyFile, '--restaurant' => 'X', '--plan' => 'pro', '--expires' => '2027-01-01', '--extras' => 'teleport']))->toBe(1);

    @unlink($keyFile);
    @rmdir($dir);
});
