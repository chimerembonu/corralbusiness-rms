<?php

use App\Modules\Customers\Services\CustomerPrivacyService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Printing\Documents\CashReportDocument;
use App\Modules\Settings\Filament\Pages\HistoryCheckPage;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Audit\AuditChain;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Complete Edition E9.4 — tamper-evident audit log: entries are sealed into a chain, and changing, removing
 * or slipping in an entry is found by the check; the Z report prints the chain's code as proof on paper.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->chain = app(AuditChain::class);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Abena']);
    $this->manager->assignRole(Role::Manager->value);
    foreach (range(1, 5) as $i) {
        activity('orders')->causedBy($this->manager)->withProperties(['amount' => $i * 100, 'note' => "Entry {$i} – ₵ ok", 'nested' => ['b' => 1.5, 'a' => [3, 2]]])->log("entry {$i}");
    }
});

function kinds(array $result): array
{
    return array_column($result['problems'], 'kind');
}

it('seals entries into a chain that checks clean, and seals new ones later', function () {
    $total = DB::table('activity_log')->count();
    expect($this->chain->seal())->toBe($total)->and($this->chain->seal())->toBe(0);

    $result = $this->chain->check();
    expect($result['problems'])->toBe([])->and($result['checked'])->toBe($total)->and($result['unsealed'])->toBe(0);

    activity('orders')->log('later');
    expect($this->chain->check()['unsealed'])->toBe(1);
    $this->chain->seal();
    expect(DB::table('activity_log')->max('chain_seq'))->toBe($total + 1)->and($this->chain->check()['problems'])->toBe([]);

    $this->artisan('rms:audit:check')->expectsOutputToContain('complete and unchanged')->assertSuccessful();
});

it('finds an entry that was changed, removed or slipped in', function () {
    $this->chain->seal();
    $seq = fn (int $n) => DB::table('activity_log')->where('chain_seq', $n)->value('id');

    // Changed: someone edits who did it, or the amount.
    DB::table('activity_log')->where('id', $seq(2))->update(['properties' => json_encode(['amount' => 1])]);
    expect(kinds($this->chain->check()))->toBe(['changed']);
    $this->artisan('rms:audit:check')->expectsOutputToContain('1 problem')->assertFailed();

    // Changed together with its own fingerprint: the link to the next entry gives it away.
    $row = DB::table('activity_log')->where('id', $seq(2))->first();
    DB::table('activity_log')->where('id', $seq(2))->update(['content_hash' => str_repeat('a', 64)]);
    expect(kinds($this->chain->check()))->toContain('link');
    DB::table('activity_log')->where('id', $seq(2))->update(['content_hash' => $row->content_hash]);

    // Removed from the middle, and from the end.
    DB::table('activity_log')->where('id', $seq(3))->delete();
    $last = (int) DB::table('activity_log')->max('chain_seq');
    DB::table('activity_log')->where('chain_seq', $last)->delete();
    expect(kinds($this->chain->check()))->toContain('missing')->toContain('end');

    // Slipped in, with a made-up place in the chain.
    DB::table('activity_log')->insert(['log_name' => 'orders', 'description' => 'fake', 'chain_seq' => 3, 'content_hash' => str_repeat('b', 64), 'chain_hash' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now()]);
    expect(kinds($this->chain->check()))->toContain('changed')->toContain('link');
});

it('prints the chain code on the Z report, and an old code no longer matches once the history is rebuilt', function () {
    $this->actingAs($this->manager);
    $till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $session = app(CashService::class)->open($till, $this->manager, Money::fromDecimal('100'), now()->toDateString());
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->manager);
    app(OrderService::class)->addItem($order, demoMenu()['coke']);
    app(PaymentService::class)->payCash($order, Money::fromDecimal('12'), $this->manager);
    app(CashService::class)->close($session, Money::fromDecimal('112'), $this->manager);

    $close = app(DayCloseService::class)->close(now()->toDateString(), $this->manager);
    $code = $close->report['history_code'];
    expect($code)->toMatch('/^\d+-[0-9A-F]{10}$/')
        ->and($this->chain->codeMatches($code))->toBeTrue()
        ->and($this->chain->codeMatches('nonsense'))->toBeNull();
    $this->get(route('pos.cash.report', ['date' => now()->toDateString()]))->assertSee('History check')->assertSee($code);
    $printed = json_encode(CashReportDocument::day($close->report, 'Z report', 'T', 'B')->lines());
    expect($printed)->toContain('History check')->toContain($code);

    // Someone with server access edits an old entry and rebuilds the whole chain so the check passes again...
    DB::table('activity_log')->where('chain_seq', 2)->update(['description' => 'edited']);
    DB::table('activity_log')->update(['chain_seq' => null, 'content_hash' => null, 'chain_hash' => null]);
    DB::table('audit_chain')->update(['last_seq' => 0, 'last_hash' => AuditChain::GENESIS]);
    $this->chain->seal();
    // ...but the code printed on the Z report gives it away: the nightly check tests it by itself (E9.6 review).
    expect(kinds($this->chain->check()))->toBe(['z_code'])
        ->and($this->chain->codeMatches($code))->toBeFalse();
    $this->artisan('rms:audit:check', ['--code' => $code])->expectsOutputToContain('does NOT match')->assertFailed();
});

it('still checks clean after customers\' details are removed on purpose, but not if the removal record is altered', function () {
    $customers = app(CustomerService::class);
    $ama = $customers->create(['name' => 'Ama Serwaa', 'phone' => '0241234567'], $this->manager);
    $ama->update(['notes' => 'Extra pepper']);
    $this->chain->seal();

    app(CustomerPrivacyService::class)->anonymise($ama->fresh(), $this->manager, 'She asked');
    $this->chain->seal();
    expect(DB::table('audit_redactions')->count())->toBeGreaterThan(0)
        ->and($this->chain->check()['problems'])->toBe([]);

    // A "removal" added quietly to hide a real change is caught.
    $victim = DB::table('activity_log')->where('chain_seq', 1)->first();
    DB::table('activity_log')->where('id', $victim->id)->update(['description' => 'edited']);
    $fresh = DB::table('activity_log')->where('id', $victim->id)->first();
    DB::table('audit_redactions')->insert(['id' => (string) Str::uuid7(), 'activity_id' => $victim->id, 'old_content_hash' => $victim->content_hash,
        'new_content_hash' => (fn () => self::contentHash($fresh, 1))->call($this->chain), 'redaction_activity_id' => DB::table('audit_redactions')->value('redaction_activity_id'),
        'created_at' => now(), 'updated_at' => now()]);
    expect(kinds($this->chain->check()))->toContain('redaction')->toContain('changed');
});

it('cannot be fooled by a forged removal record that excuses a changed amount (E9.6 review H1)', function () {
    activity('payments')->withProperties(['amount' => 5000])->log('refund');
    $this->chain->seal();
    $code = $this->chain->code();

    // Someone with database access only changes a sealed refund amount, then fakes a "details removed" record for it.
    $row = DB::table('activity_log')->where('description', 'refund')->first();
    DB::table('activity_log')->where('id', $row->id)->update(['properties' => json_encode(['amount' => 100])]);
    $fresh = DB::table('activity_log')->where('id', $row->id)->first();
    $list = [['activity_id' => (int) $row->id, 'old_content_hash' => $row->content_hash,
        'new_content_hash' => (fn () => self::contentHash($fresh, (int) $row->chain_seq))->call($this->chain)]];
    $fake = DB::table('activity_log')->insertGetId(['log_name' => 'customers', 'description' => 'customer_anonymised',
        'properties' => json_encode(['redactions' => AuditChain::redactionDigest($list)]), 'created_at' => now(), 'updated_at' => now()]);
    $this->chain->storeRedactions($list, $fake, null);
    $this->chain->seal();

    // A payment entry can never have details "removed", and the amount is in the second fingerprint anyway.
    expect(kinds($this->chain->check()))->toContain('changed')->toContain('redaction')
        ->and($this->chain->codeMatches($code))->toBeTrue(); // the old code still matches: the check itself finds it

    // Even dressed up as an order entry, only personal details may differ.
    DB::table('activity_log')->where('id', $row->id)->update(['subject_type' => (new Order)->getMorphClass()]);
    expect(kinds($this->chain->check()))->toContain('changed');
});

it('shows the owner the History check page, with a check now and a printed-code test', function () {
    $owner = Tenancy::runAs($this->branch->tenant, null, function () {
        $owner = User::factory()->create(['tenant_id' => $this->branch->tenant_id, 'branch_id' => null]);
        $owner->assignRole(Role::Owner->value);

        return $owner;
    });

    $this->actingAs($this->manager)->get(HistoryCheckPage::getUrl())->assertForbidden();

    $this->flushSession(); // a new browser session for the owner
    $this->actingAs($owner);
    $this->get(HistoryCheckPage::getUrl())->assertOk()->assertSee('Not checked yet');
    Livewire::test(HistoryCheckPage::class)->callAction('checkNow')->assertNotified('The history is complete and unchanged ('.DB::table('activity_log')->count().' entries checked).')
        ->assertSee('complete and unchanged');
    $code = $this->chain->code();
    Livewire::test(HistoryCheckPage::class)->callAction('testCode', ['code' => $code])->assertNotified("Code {$code} matches: the history up to that Z report is unchanged.");
    Livewire::test(HistoryCheckPage::class)->callAction('testCode', ['code' => '3-0000000000'])->assertNotified('Code 3-0000000000 does NOT match: the history up to that Z report was changed. Call Corral Systems support.');

    DB::table('activity_log')->where('chain_seq', 1)->update(['description' => 'edited']);
    $this->chain->check();
    expect(HistoryCheckPage::getNavigationBadge())->toBe('1');
    $this->get(HistoryCheckPage::getUrl())->assertSee('Entry no. 1 was changed after it was written.');
});
