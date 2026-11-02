<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Security review M1: a line discount that needed a manager remembers which manager approved it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', fn (Blueprint $t) => $t->foreignUuid('item_discount_approved_by_id')->nullable()->after('item_discount_reason')->constrained('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('item_discount_approved_by_id');
        });
    }
};
