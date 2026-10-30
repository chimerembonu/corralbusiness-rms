<?php

namespace App\Support\Filament;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Fieldset;

/** The "when can this be sold" fields shared by categories and items. */
final class AvailabilityFields
{
    public static function make(): Fieldset
    {
        return Fieldset::make(__('menu::menu.availability.days'))
            ->columns(3)
            ->schema([
                CheckboxList::make('availability.days')
                    ->label(__('menu::menu.availability.days'))
                    ->options(__('menu::menu.days'))
                    ->columns(2)
                    ->columnSpan(1),
                TimePicker::make('availability.from')->label(__('menu::menu.availability.from'))->seconds(false),
                TimePicker::make('availability.to')->label(__('menu::menu.availability.to'))->seconds(false)
                    ->helperText(__('menu::menu.availability.hint')),
            ]);
    }
}
