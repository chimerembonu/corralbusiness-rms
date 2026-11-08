<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete Edition E5.2: every change to an ingredient's cost, append-only. It is the purchase price history
 * (what was paid, how much, from which delivery) and the record of cost changes made by hand (with a reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_cost_changes', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('source', 20);                          // opening | purchase | manual | import
            $table->unsignedInteger('old_cost');                   // pesewas per unit, before
            $table->unsignedInteger('new_cost');                   // pesewas per unit, after (the average after a delivery)
            $table->unsignedInteger('price_paid')->nullable();     // pesewas per unit on the delivery
            $table->decimal('quantity', 12, 3)->nullable();        // delivered
            $table->decimal('on_hand_before', 12, 3)->nullable();
            $table->foreignUuid('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();
            $table->string('reason', 255)->nullable();             // required for changes by hand
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('happened_at');

            $table->index(['ingredient_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_cost_changes');
    }
};
