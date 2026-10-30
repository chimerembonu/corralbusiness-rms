<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ingredients with units and a simple on-hand quantity per branch.
        // Suppliers, purchases, stock takes and recipes build on this in Phases 2.2 and 4.
        Schema::create('ingredients', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('name', 100);
            $table->string('code', 30)->nullable();
            $table->string('unit', 10);                       // kg | g | l | ml | pcs
            $table->unsignedInteger('cost_per_unit')->default(0); // pesewas
            $table->decimal('on_hand', 12, 3)->default(0);
            $table->decimal('reorder_level', 12, 3)->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['branch_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
