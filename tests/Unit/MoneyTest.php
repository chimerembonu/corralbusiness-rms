<?php

use App\Support\Money\Money;

it('parses decimals into pesewas with half-up rounding', function () {
    expect(Money::fromDecimal('12.50')->amount)->toBe(1250)
        ->and(Money::fromDecimal('0.005')->amount)->toBe(1)
        ->and(Money::fromDecimal('0.004')->amount)->toBe(0)
        ->and(Money::fromDecimal(12.5)->amount)->toBe(1250)
        ->and(Money::fromDecimal('1,250.00')->amount)->toBe(125000)
        ->and(Money::fromDecimal('-3.25')->amount)->toBe(-325)
        ->and(Money::fromDecimal('7')->amount)->toBe(700);
});

it('rounds percentages and basis points half-up to the pesewa', function () {
    expect(Money::of(1001)->basisPoints(250)->amount)->toBe(25)     // 25.025 → 25
        ->and(Money::of(1003)->basisPoints(250)->amount)->toBe(25)  // 25.075 → 25
        ->and(Money::of(1002)->basisPoints(250)->amount)->toBe(25)  // 25.05 → 25 (exact: 25.05 → 25? no: 25.05 rounds to 25)
        ->and(Money::of(1010)->basisPoints(250)->amount)->toBe(25)  // 25.25 → 25
        ->and(Money::of(1020)->basisPoints(250)->amount)->toBe(26)  // 25.5 → 26 (half-up)
        ->and(Money::of(10000)->percentage(15)->amount)->toBe(1500)
        ->and(Money::of(-1020)->basisPoints(250)->amount)->toBe(-26);
});

it('adds, subtracts and multiplies', function () {
    $a = Money::of(1000);
    $b = Money::of(250);

    expect($a->add($b)->amount)->toBe(1250)
        ->and($a->subtract($b)->amount)->toBe(750)
        ->and($a->multiply(3)->amount)->toBe(3000)
        ->and($a->multiply(0.333)->amount)->toBe(333)
        ->and($a->multiply(1.5)->amount)->toBe(1500);
});

it('splits without losing a pesewa', function () {
    $parts = Money::of(1000)->split(3);

    expect(array_map(fn (Money $m) => $m->amount, $parts))->toBe([334, 333, 333])
        ->and(array_sum(array_map(fn (Money $m) => $m->amount, Money::of(100)->split(7))))->toBe(100);
});

it('allocates by ratio without losing a pesewa', function () {
    $parts = Money::of(1000)->allocate(['food' => 70, 'drinks' => 30]);

    expect($parts['food']->amount)->toBe(700)
        ->and($parts['drinks']->amount)->toBe(300);

    $odd = Money::of(1001)->allocate([1, 1]);
    expect($odd[0]->amount + $odd[1]->amount)->toBe(1001);
});

it('formats for receipts and exports', function () {
    expect(Money::of(125000)->format())->toBe('GH₵ 1,250.00')
        ->and(Money::of(5)->format(false))->toBe('0.05')
        ->and(Money::of(-1250)->toDecimal())->toBe('-12.50')
        ->and(money(1250))->toBe('GH₵ 12.50')
        ->and(money(null))->toBe('GH₵ 0.00')
        ->and((string) Money::of(100))->toBe('GH₵ 1.00');
});

it('refuses to mix currencies', function () {
    Money::of(100, 'GHS')->add(Money::of(100, 'USD'));
})->throws(InvalidArgumentException::class);

it('compares amounts', function () {
    expect(Money::of(100)->greaterThan(Money::of(99)))->toBeTrue()
        ->and(Money::of(100)->equals(Money::of(100)))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::of(-1)->isNegative())->toBeTrue();
});
