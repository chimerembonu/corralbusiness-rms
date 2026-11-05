<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Complete Edition E2.4: money sent back through the payment provider that took it (or "refund by hand"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_refunds', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('gateway_transaction_id')->constrained('gateway_transactions');
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->string('gateway', 30);
            $table->string('reference', 64)->unique();             // ours, sent to the provider: never refunded twice
            $table->string('provider_reference', 100)->nullable(); // theirs (Paystack's refund id)
            $table->unsignedInteger('amount');                     // pesewas sent back (payment + tip)
            $table->string('status', 20)->default('queued');       // RefundStatus
            $table->string('message', 255)->nullable();
            $table->string('reason', 255)->nullable();             // the manager's reason
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('done_by_hand_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('done_by_hand_note', 255)->nullable();  // e.g. the MoMo reference of the money sent back
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('checks')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index(['status', 'next_check_at']);
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_refunds');
    }
};
