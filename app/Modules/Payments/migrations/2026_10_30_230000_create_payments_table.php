<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment taken. An order may have several (split payments, Phase 1).
        Schema::create('payments', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('method', 20);                        // cash | momo | card | account
            $table->unsignedInteger('amount');                   // applied to the order (pesewas)
            $table->unsignedInteger('tendered')->nullable();     // cash handed over
            $table->unsignedInteger('change_given')->default(0);
            $table->string('reference', 100)->nullable();        // MoMo transaction id, card slip no.
            $table->string('status', 15)->default('completed');  // completed | refunded
            $table->foreignUuid('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->string('notes', 255)->nullable();

            $table->index(['branch_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
