<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Menu is tenant-wide (branch_id null = every branch). Per-branch prices live in menu_branch_prices.
        Schema::create('menu_categories', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('color', 9)->nullable();     // button colour on the POS, e.g. #16a34a
            $table->string('image_path')->nullable();
            $table->json('availability')->nullable();   // {"days":[1..7],"from":"HH:MM","to":"HH:MM"} null = always
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->foreignUuid('category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->string('type', 10)->default('standard'); // standard | combo
            $table->string('name', 120);
            $table->string('code', 30)->nullable();         // short code / SKU / PLU
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('price');                // pesewas; base price when there are no variants
            $table->string('image_path')->nullable();
            $table->boolean('taxable')->default(true);
            $table->json('tax_exempt_codes')->nullable();    // e.g. ["VAT"] exempt from one component only
            $table->json('allergens')->nullable();           // ["fish","peanuts"]
            $table->json('tags')->nullable();                // ["spicy","popular"]
            $table->json('availability')->nullable();
            $table->boolean('is_sold_out')->default(false);  // "86" from the POS
            $table->timestamp('sold_out_at')->nullable();
            $table->boolean('track_stock')->default(false);  // Phase 2: count-based stock for drinks
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'category_id', 'is_active']);
        });

        Schema::create('menu_item_variants', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->foreignUuid('item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('name', 60);                      // Small / Regular / Large, 1 tilapia / 2 tilapia
            $table->string('code', 30)->nullable();
            $table->unsignedInteger('price');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('menu_modifier_groups', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('name', 80);                      // "Choose a protein", "Extras"
            $table->string('description', 255)->nullable();
            $table->unsignedTinyInteger('min_select')->default(0); // 1+ = required
            $table->unsignedTinyInteger('max_select')->default(1); // 0 = no limit
            $table->boolean('allow_quantity')->default(false);     // "2 × extra egg"
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('menu_modifiers', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->foreignUuid('modifier_group_id')->constrained('menu_modifier_groups')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('price')->default(0);    // pesewas added to the item
            $table->boolean('is_default')->default(false);
            $table->boolean('is_sold_out')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
        });

        // Which modifier groups an item (or combo) offers.
        Schema::create('menu_item_modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignUuid('modifier_group_id')->constrained('menu_modifier_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'modifier_group_id']);
        });

        // Fixed contents of a combo item (e.g. Jollof + Grilled Chicken + Drink).
        Schema::create('menu_combo_items', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->foreignUuid('combo_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('menu_item_variants')->nullOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        // Per-branch price override and per-branch "not sold here".
        Schema::create('menu_branch_prices', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('menu_item_variants')->cascadeOnDelete();
            $table->unsignedInteger('price')->nullable();    // null = use the standard price
            $table->boolean('is_available')->default(true);  // false = hidden at this branch

            $table->unique(['branch_id', 'item_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_branch_prices');
        Schema::dropIfExists('menu_combo_items');
        Schema::dropIfExists('menu_item_modifier_groups');
        Schema::dropIfExists('menu_modifiers');
        Schema::dropIfExists('menu_modifier_groups');
        Schema::dropIfExists('menu_item_variants');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
    }
};
