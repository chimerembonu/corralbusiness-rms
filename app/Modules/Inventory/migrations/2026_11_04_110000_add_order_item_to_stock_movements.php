<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security review M2: a sale or void movement records the order line it came from, so the same line
 * can never be deducted (or put back) twice, however many times the kitchen event fires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->uuid('order_item_id')->nullable()->after('reference_id');
            $t->index(['order_item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropIndex(['order_item_id', 'type']);
            $t->dropColumn('order_item_id');
        });
    }
};
