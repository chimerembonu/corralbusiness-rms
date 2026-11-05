<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Complete Edition E5.1 follow-up: the ingredients one extra uses (e.g. Chicken: 150 g chicken), per branch. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_recipe_lines', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('modifier_id')->constrained('menu_modifiers')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);   // for one of this extra, in `unit`
            $table->string('unit', 10);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['branch_id', 'modifier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_recipe_lines');
    }
};
