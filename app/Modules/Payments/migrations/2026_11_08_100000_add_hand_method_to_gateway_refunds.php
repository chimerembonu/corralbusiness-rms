<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E9.6 review F5 — a refund given back by hand says how: cash from a till (then it is a pay-out from that
 * till's drawer, so cash-up still balances) or Mobile Money sent from the restaurant's phone (with its reference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->string('done_by_hand_method', 10)->nullable()->after('done_by_hand_note'); // cash | momo | other
            $table->foreignUuid('cash_movement_id')->nullable()->after('done_by_hand_method')->constrained('cash_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_movement_id');
            $table->dropColumn('done_by_hand_method');
        });
    }
};
