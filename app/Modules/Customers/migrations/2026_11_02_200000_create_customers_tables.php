<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers belong to the restaurant, not one branch.
        Schema::create('customers', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();            // as typed
            $table->string('phone_digits', 20)->nullable();     // digits only, for lookup
            $table->string('email', 120)->nullable();
            $table->string('notes', 255)->nullable();
            $table->json('tags')->nullable();
            $table->boolean('allow_account')->default(false);   // may buy on credit
            $table->unsignedInteger('credit_limit')->nullable(); // pesewas; null = no limit
            $table->integer('account_balance')->default(0);     // pesewas owed to us (+)
            $table->integer('points_balance')->default(0);
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0); // pesewas
            $table->timestamp('last_order_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unique(['tenant_id', 'phone_digits']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 40)->nullable();            // Home, Office…
            $table->string('address', 255);
            $table->string('notes', 160)->nullable();           // directions
            $table->boolean('is_default')->default(false);
        });

        // The customer's account: charges (orders put on account) and payments against it.
        Schema::create('account_transactions', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type', 12);                         // charge | payment | adjustment
            $table->integer('amount');                          // pesewas; + raises what they owe, − lowers it
            $table->integer('balance_after');
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('happened_at');

            $table->index(['customer_id', 'happened_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->after('customer_phone')->constrained('customers')->nullOnDelete();
        });

        // An account payment is a payment with a customer and no order.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignUuid('customer_id')->nullable()->after('order_id')->constrained('customers')->nullOnDelete();
            $table->uuid('order_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('account_transactions');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
