<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete Edition E5.1: recipes. One row per ingredient a dish uses, per branch (each branch counts its own
 * ingredients). variant_id empty = for every size; set = extra for that size only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('menu_item_variants')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);         // per portion, in `unit`
            $table->string('unit', 10);                 // the ingredient's unit, or a compatible one (g for kg, ml for L)
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['branch_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
    }
};
