<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security review H5: once a bill has been shown or printed for an order, removing lines or cancelling
 * it needs a manager (void path), and removed lines remember who removed them for the voids report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->timestamp('bill_printed_at')->nullable()->after('is_training'));
        Schema::table('order_items', fn (Blueprint $t) => $t->foreignUuid('removed_by_id')->nullable()->after('voided_at')->constrained('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('bill_printed_at'));
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('removed_by_id');
        });
    }
};
