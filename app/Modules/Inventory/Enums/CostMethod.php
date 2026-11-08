<?php

namespace App\Modules\Inventory\Enums;

/**
 * How ingredients are valued (stock value, food cost, gross margin). The owner or the accountant chooses it
 * at setup or later (Settings → Stock costing). IAS 2, which Ghana follows, allows the weighted average or
 * first-in-first-out. "Latest price paid" is a simple shortcut whose stock value comes close to
 * first-in-first-out when food is bought and used within days, as in most restaurants.
 */
enum CostMethod: string
{
    case Average = 'average';
    case LastPrice = 'last_price';

    /** For a restaurant that has not chosen: the IAS 2 method. */
    public static function default(): self
    {
        return self::Average;
    }

    public function label(): string
    {
        return __('inventory::inventory.costs.methods.'.$this->value.'.name');
    }

    public function about(): string
    {
        return __('inventory::inventory.costs.methods.'.$this->value.'.about');
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
