<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kitchen stations (Grill, Fry, Bar…). Phase 0 uses one screen for everything; routing comes in 1.4.
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('name', 50);
            $table->string('code', 20);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['branch_id', 'code']);
        });

        // One ticket per "send to kitchen". Items keep their own status for later per-item bumping.
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('station_id')->nullable()->constrained('kitchen_stations')->nullOnDelete();
            $table->unsignedInteger('order_number');
            $table->string('order_type', 15);
            $table->string('table_name', 30)->nullable();
            $table->string('server_name', 120)->nullable();
            $table->string('status', 15)->default('new');      // new | in_progress | done
            $table->text('notes')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignUuid('bumped_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['branch_id', 'status', 'sent_at']);
        });

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('ticket_id')->constrained('kitchen_tickets')->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->string('name', 160);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->json('modifiers')->nullable();            // ["Chicken", "2 × Extra plantain"]
            $table->string('notes', 255)->nullable();
            $table->unsignedTinyInteger('seat')->nullable();
            $table->boolean('is_voided')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('kitchen_stations');
    }
};
