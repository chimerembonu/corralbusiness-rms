<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete Edition E2: one row per request sent to a payment provider (a MoMo prompt, an online checkout).
 * It remembers which provider handled it, so switching the default provider never loses track of payments
 * already on their way. Never holds card details; the phone number is kept masked. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_transactions', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('gateway', 30);                          // key in config/payments.php
            $table->string('purpose', 20);                          // till_momo | online
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('reference', 40)->unique();              // ours, sent to the provider
            $table->string('provider_reference', 100)->nullable();  // theirs
            $table->unsignedInteger('amount');                      // pesewas, applied to the order
            $table->unsignedInteger('tip')->default(0);
            $table->string('currency', 3)->default('GHS');
            $table->string('network', 10)->nullable();              // mtn | telecel | at
            $table->string('phone_masked', 20)->nullable();         // e.g. 024•••5678
            $table->string('status', 20)->default('pending');
            $table->string('message', 255)->nullable();             // what the provider/customer sees
            $table->boolean('is_training')->default(false);
            $table->foreignUuid('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('checks')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->index(['branch_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_transactions');
    }
};
