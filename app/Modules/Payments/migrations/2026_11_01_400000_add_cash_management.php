<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row each time a cashier opens a till with a float and later counts it (cash-up).
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->businessColumns();
            $table->unsignedInteger('number');                     // 1, 2, 3… per branch
            $table->date('business_date');
            $table->foreignUuid('till_id')->nullable()->constrained('tills')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();   // the cashier
            $table->string('status', 10)->default('open');         // open | closed
            $table->unsignedInteger('opening_float');              // pesewas
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete(); // manager who accepted a big variance
            $table->unsignedInteger('cash_sales')->default(0);     // cash applied to orders (incl. tips in cash)
            $table->unsignedInteger('cash_tips')->default(0);
            $table->unsignedInteger('cash_refunds')->default(0);
            $table->unsignedInteger('pay_ins')->default(0);
            $table->unsignedInteger('pay_outs')->default(0);
            $table->unsignedInteger('expected_cash')->nullable();
            $table->unsignedInteger('counted_cash')->nullable();
            $table->integer('variance')->nullable();               // counted − expected (negative = short)
            $table->json('counted_breakdown')->nullable();         // {"20000": 3, "1000": 12, …} note value → count
            $table->json('totals')->nullable();                    // by method snapshot at close
            $table->string('notes', 255)->nullable();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'business_date']);
        });

        // Money in or out of the drawer that is not a sale: change from the bank, a supplier paid in cash…
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('cashier_session_id')->constrained('cashier_sessions')->cascadeOnDelete();
            $table->string('type', 10);                            // pay_in | pay_out
            $table->unsignedInteger('amount');
            $table->string('reason', 120);
            $table->string('reference', 100)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('happened_at');
        });

        // One row per Z report (day close). Reopening keeps the row and marks it.
        Schema::create('day_closes', function (Blueprint $table) {
            $table->businessColumns();
            $table->unsignedInteger('number');                     // Z number, per branch, never reused
            $table->date('business_date');
            $table->string('status', 10)->default('closed');       // closed | reopened
            $table->timestamp('closed_at');
            $table->foreignUuid('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignUuid('reopened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 255)->nullable();
            $table->json('report');                                // the Z report as printed (snapshot)
            $table->string('notes', 255)->nullable();

            $table->index(['branch_id', 'business_date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignUuid('cashier_session_id')->nullable()->after('order_id')->constrained('cashier_sessions')->nullOnDelete();
            $table->foreignUuid('refund_session_id')->nullable()->after('refunded_by_id')->constrained('cashier_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_session_id');
            $table->dropConstrainedForeignId('cashier_session_id');
        });
        Schema::dropIfExists('day_closes');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashier_sessions');
    }
};
