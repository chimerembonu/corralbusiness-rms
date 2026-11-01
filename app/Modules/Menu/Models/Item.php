<?php

namespace App\Modules\Menu\Models;

use App\Modules\Menu\Enums\ItemType;
use App\Modules\Menu\Support\HasAvailability;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sellable menu item (or a combo bundle when type = combo). Prices are in pesewas. */
class Item extends BusinessModel
{
    use Auditable;
    use HasAvailability;

    protected $table = 'menu_items';

    /** Tenant-wide table: branch_id is only set when a row is specific to one branch. */
    protected bool $hasBranch = false;

    protected $fillable = [
        'branch_id', 'category_id', 'type', 'name', 'code', 'description', 'price', 'image_path',
        'taxable', 'tax_exempt_codes', 'allergens', 'tags', 'availability',
        'is_sold_out', 'sold_out_at', 'track_stock', 'stock_qty', 'stock_reorder_level', 'sort_order', 'is_active',
    ];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['type' => 'standard', 'taxable' => true, 'is_sold_out' => false, 'track_stock' => false, 'sort_order' => 0, 'is_active' => true];

    protected array $auditExcept = ['sold_out_at'];

    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'price' => MoneyCast::class,
            'taxable' => 'boolean',
            'tax_exempt_codes' => 'array',
            'allergens' => 'array',
            'tags' => 'array',
            'availability' => 'array',
            'is_sold_out' => 'boolean',
            'sold_out_at' => 'datetime',
            'track_stock' => 'boolean',
            'stock_qty' => 'decimal:3',
            'stock_reorder_level' => 'decimal:3',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class, 'item_id')->orderBy('sort_order')->orderBy('name');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_item_modifier_groups', 'item_id', 'modifier_group_id')
            ->using(ItemModifierGroup::class)
            ->withPivot(['id', 'tenant_id', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /** Contents when this item is a combo. */
    public function comboItems(): HasMany
    {
        return $this->hasMany(ComboItem::class, 'combo_id')->orderBy('sort_order');
    }

    public function branchPrices(): HasMany
    {
        return $this->hasMany(BranchPrice::class, 'item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->active()->where('is_sold_out', false);
    }

    /** Counted stock is low (only for items with "count stock" ticked). */
    public function isStockLow(): bool
    {
        return $this->track_stock && (float) $this->stock_reorder_level > 0 && (float) $this->stock_qty <= (float) $this->stock_reorder_level;
    }

    public function isCombo(): bool
    {
        return $this->type === ItemType::Combo;
    }

    public function hasVariants(): bool
    {
        return $this->relationLoaded('variants') ? $this->variants->isNotEmpty() : $this->variants()->exists();
    }

    /** The standard (tenant-wide) price: the default size's price when one is marked default, else the item price. */
    public function basePrice(): Money
    {
        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();
        $default = $variants->firstWhere('is_default', true);

        return $default?->price ?? $this->price;
    }
}
