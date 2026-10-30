<?php

namespace App\Support\Filament;

use App\Support\Money\Money;
use Filament\Forms\Components\TextInput;

/** A price box in cedis that stores pesewas (through MoneyCast on the model). */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        // Not ->numeric(): that casts the state to float before our formatter sees the Money object.
        return TextInput::make($name)
            ->inputMode('decimal')
            ->rules(['nullable', 'numeric', 'min:0'])
            ->prefix(config('rms.currency_symbol'))
            ->afterStateHydrated(fn (TextInput $component, $state) => $component->state(self::toDecimal($state)))
            ->formatStateUsing(fn ($state) => self::toDecimal($state))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::fromDecimal((string) $state)->amount);
    }

    public static function toDecimal(mixed $state): ?string
    {
        return match (true) {
            $state === null, $state === '' => null,
            $state instanceof Money => $state->toDecimal(),
            is_array($state) && isset($state['amount']) => Money::of((int) $state['amount'])->toDecimal(),
            is_int($state) => Money::of($state)->toDecimal(),
            default => (string) $state,
        };
    }

    public static function format(mixed $state): string
    {
        return match (true) {
            $state === null => '',
            $state instanceof Money => $state->format(),
            is_array($state) && isset($state['amount']) => money((int) $state['amount']),
            is_int($state) => money($state),
            default => money(Money::fromDecimal((string) $state)),
        };
    }
}
