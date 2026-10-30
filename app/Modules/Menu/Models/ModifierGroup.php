<?php

namespace App\Modules\Menu\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Choose a protein" (required, pick 1) or "Extras" (optional, up to 5). */
class ModifierGroup extends BusinessModel
{
    use Auditable;

    protected $table = 'menu_modifier_groups';

    protected $fillable = ['branch_id', 'name', 'description', 'min_select', 'max_select', 'allow_quantity', 'sort_order', 'is_active'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['min_select' => 0, 'max_select' => 1, 'allow_quantity' => false, 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'min_select' => 'integer',
            'max_select' => 'integer',
            'allow_quantity' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'modifier_group_id')->orderBy('sort_order')->orderBy('name');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'menu_item_modifier_groups', 'modifier_group_id', 'item_id')
            ->using(ItemModifierGroup::class)
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    public function isUnlimited(): bool
    {
        return $this->max_select === 0;
    }

    /** Human rule for the POS: "Required · pick 1", "Optional · up to 3". */
    public function rule(): string
    {
        $required = $this->isRequired() ? __('menu::menu.modifier.required') : __('menu::menu.modifier.optional');

        if ($this->isUnlimited()) {
            return $required.' · '.__('menu::menu.modifier.pick_any', ['min' => (string) $this->min_select]);
        }

        if ($this->min_select === $this->max_select) {
            return $required.' · '.__('menu::menu.modifier.pick_exactly', ['count' => (string) $this->max_select]);
        }

        return $required.' · '.__('menu::menu.modifier.pick_between', ['min' => (string) $this->min_select, 'max' => (string) $this->max_select]);
    }

    /**
     * Check a chosen set of modifiers against the min/max rules.
     *
     * @param  array<int, string>  $modifierIds  ids chosen (repeat an id for quantity > 1)
     * @return array<int, string> error messages, empty when valid
     */
    public function validateSelection(array $modifierIds): array
    {
        $count = count($modifierIds);
        $errors = [];

        if ($count < $this->min_select) {
            $errors[] = __('menu::menu.modifier.too_few', ['group' => $this->name, 'min' => (string) $this->min_select]);
        }

        if (! $this->isUnlimited() && $count > $this->max_select) {
            $errors[] = __('menu::menu.modifier.too_many', ['group' => $this->name, 'max' => (string) $this->max_select]);
        }

        if (! $this->allow_quantity && count(array_unique($modifierIds)) !== $count) {
            $errors[] = __('menu::menu.modifier.no_quantity', ['group' => $this->name]);
        }

        $known = $this->modifiers()->active()->pluck('id')->all();
        foreach (array_unique($modifierIds) as $id) {
            if (! in_array($id, $known, true)) {
                $errors[] = __('menu::menu.modifier.unknown', ['group' => $this->name]);
                break;
            }
        }

        return $errors;
    }
}
