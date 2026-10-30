<?php

use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\Modifier;
use App\Modules\Menu\Models\ModifierGroup;

beforeEach(function () {
    provisionTenant();
    $this->protein = ModifierGroup::create(['name' => 'Choose a protein', 'min_select' => 1, 'max_select' => 1]);
    $this->chicken = Modifier::create(['modifier_group_id' => $this->protein->id, 'name' => 'Chicken', 'price' => 1500]);
    $this->beef = Modifier::create(['modifier_group_id' => $this->protein->id, 'name' => 'Beef', 'price' => 1500]);
    $this->extras = ModifierGroup::create(['name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'allow_quantity' => true]);
    $this->egg = Modifier::create(['modifier_group_id' => $this->extras->id, 'name' => 'Egg', 'price' => 500]);
});

it('enforces a required single choice', function () {
    expect($this->protein->validateSelection([]))->toHaveCount(1)
        ->and($this->protein->validateSelection([$this->chicken->id]))->toBe([])
        ->and($this->protein->validateSelection([$this->chicken->id, $this->beef->id]))->toHaveCount(1)
        ->and($this->protein->validateSelection([$this->chicken->id, $this->chicken->id]))->not->toBe([]);
});

it('allows optional extras up to the maximum, with quantities when enabled', function () {
    expect($this->extras->validateSelection([]))->toBe([])
        ->and($this->extras->validateSelection([$this->egg->id, $this->egg->id]))->toBe([])
        ->and($this->extras->validateSelection([$this->egg->id, $this->egg->id, $this->egg->id]))->toHaveCount(1)
        ->and($this->extras->validateSelection(['not-a-real-id']))->toHaveCount(1);
});

it('describes the rule for the POS', function () {
    expect($this->protein->rule())->toBe('Required · pick 1')
        ->and($this->extras->rule())->toBe('Optional · pick 0 to 2')
        ->and(ModifierGroup::create(['name' => 'Any', 'max_select' => 0])->rule())->toBe('Optional · pick 0 or more');
});

it('attaches modifier groups to items in order with a tenant-stamped pivot', function () {
    $item = Item::create(['category_id' => Category::create(['name' => 'Rice'])->id, 'name' => 'Jollof', 'price' => 4500]);
    $item->modifierGroups()->sync([$this->extras->id => ['sort_order' => 1], $this->protein->id => ['sort_order' => 0]]);

    $groups = $item->fresh()->modifierGroups;

    expect($groups->pluck('name')->all())->toBe(['Choose a protein', 'Extras'])
        ->and($groups->first()->pivot->tenant_id)->toBe($item->tenant_id)
        ->and($groups->first()->pivot->id)->not->toBeNull();
});
