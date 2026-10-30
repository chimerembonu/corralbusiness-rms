<?php

use App\Modules\Settings\Services\TaxService;
use App\Support\Money\Money;
use App\Support\Tax\TaxBase;
use App\Support\Tax\TaxCalculator;
use App\Support\Tax\TaxComponentData;
use App\Support\Tax\TaxKind;
use App\Support\Tax\TaxProfile;
use App\Support\Tax\TaxResult;

function ghana2026(bool $inclusive = false): TaxProfile
{
    return new TaxProfile([
        new TaxComponentData('NHIL', 'NHIL', 250, TaxKind::Levy, TaxBase::Net, 1),
        new TaxComponentData('GETFUND', 'GETFund Levy', 250, TaxKind::Levy, TaxBase::Net, 2),
        new TaxComponentData('VAT', 'VAT', 1500, TaxKind::Vat, TaxBase::Net, 3),
    ], $inclusive);
}

function ghanaPre2026(bool $inclusive = false): TaxProfile
{
    return new TaxProfile([
        new TaxComponentData('NHIL', 'NHIL', 250, TaxKind::Levy, TaxBase::Net, 1),
        new TaxComponentData('GETFUND', 'GETFund Levy', 250, TaxKind::Levy, TaxBase::Net, 2),
        new TaxComponentData('COVID', 'COVID-19 Levy', 100, TaxKind::Levy, TaxBase::Net, 3),
        new TaxComponentData('VAT', 'VAT', 1500, TaxKind::Vat, TaxBase::NetPlusLevies, 4),
    ], $inclusive);
}

it('matches the GRA 2026 example: GHS 1,000 net becomes GHS 1,200', function () {
    $result = (new TaxCalculator)->calculate(Money::of(100000), ghana2026());

    expect($result->net)->toBePesewas(100000)
        ->and($result->line('NHIL')->amount)->toBePesewas(2500)
        ->and($result->line('GETFUND')->amount)->toBePesewas(2500)
        ->and($result->line('VAT')->amount)->toBePesewas(15000)
        ->and($result->tax())->toBePesewas(20000)
        ->and($result->gross)->toBePesewas(120000);
});

it('supports the pre-2026 cascading method: GHS 1,000 net becomes GHS 1,219', function () {
    $result = (new TaxCalculator)->calculate(Money::of(100000), ghanaPre2026());

    expect($result->line('COVID')->amount)->toBePesewas(1000)
        ->and($result->line('VAT')->amount)->toBePesewas(15900) // 15 % of 1,060
        ->and($result->gross)->toBePesewas(121900);
});

it('backs the net out of a tax-inclusive price', function () {
    $result = (new TaxCalculator)->calculate(Money::of(120000), ghana2026(inclusive: true));

    expect($result->gross)->toBePesewas(120000)
        ->and($result->net)->toBePesewas(100000)
        ->and($result->line('VAT')->amount)->toBePesewas(15000)
        ->and($result->line('NHIL')->amount)->toBePesewas(2500)
        ->and($result->tax())->toBePesewas(20000);
});

it('always keeps net + tax equal to gross on awkward inclusive prices', function () {
    $calculator = new TaxCalculator;

    foreach ([1, 7, 99, 333, 1999, 4567, 123457, 999999] as $gross) {
        foreach ([ghana2026(true), ghanaPre2026(true)] as $profile) {
            $result = $calculator->calculate(Money::of($gross), $profile);
            expect($result->net->add($result->tax())->amount)->toBe($gross);
            foreach ($result->lines as $line) {
                expect($line->amount->amount)->toBeGreaterThanOrEqual(0);
            }
        }
    }
});

it('rounds each component half-up to the pesewa', function () {
    // 1,020 pesewas × 2.5 % = 25.5 → 26 ; × 15 % = 153
    $result = (new TaxCalculator)->calculate(Money::of(1020), ghana2026());

    expect($result->line('NHIL')->amount)->toBePesewas(26)
        ->and($result->line('VAT')->amount)->toBePesewas(153)
        ->and($result->gross)->toBePesewas(1020 + 26 + 26 + 153);
});

it('charges nothing on a tax-exempt item', function () {
    $result = (new TaxCalculator)->calculate(Money::of(5000), ghana2026(), taxable: false);

    expect($result->lines)->toBe([])
        ->and($result->tax())->toBePesewas(0)
        ->and($result->gross)->toBePesewas(5000);
});

it('can exempt an item from a single component', function () {
    $result = (new TaxCalculator)->calculate(Money::of(10000), ghana2026(), exemptCodes: ['VAT']);

    expect($result->line('VAT'))->toBeNull()
        ->and($result->tax())->toBePesewas(500)
        ->and($result->gross)->toBePesewas(10500);
});

it('sums several lines into one receipt summary', function () {
    $calculator = new TaxCalculator;
    $jollof = $calculator->calculate(Money::of(4500), ghana2026());
    $malta = $calculator->calculate(Money::of(1200), ghana2026());
    $water = $calculator->calculate(Money::of(500), ghana2026(), taxable: false);

    $order = TaxResult::sum($jollof, $malta, $water);

    expect($order->net)->toBePesewas(6200)
        ->and($order->line('VAT')->amount)->toBePesewas(675 + 180)
        ->and($order->line('NHIL')->amount)->toBePesewas(113 + 30)
        ->and($order->gross)->toBePesewas($jollof->gross->amount + $malta->gross->amount + 500);
});

it('works with no tax components at all', function () {
    $result = (new TaxCalculator)->calculate(Money::of(1000), TaxProfile::none(true));

    expect($result->net)->toBePesewas(1000)->and($result->gross)->toBePesewas(1000);
});

it('loads the seeded Ghana defaults for a tenant from the database', function () {
    provisionTenant();

    $profile = app(TaxService::class)->profileFor();

    expect($profile->pricesIncludeTax)->toBeFalse()
        ->and(array_map(fn ($c) => $c->code, $profile->components))->toBe(['NHIL', 'GETFUND', 'VAT'])
        ->and($profile->effectiveRate())->toBe(0.2);

    app(TaxService::class)->setPricesIncludeTax(true);

    expect(app(TaxService::class)->profileFor()->pricesIncludeTax)->toBeTrue();
});
