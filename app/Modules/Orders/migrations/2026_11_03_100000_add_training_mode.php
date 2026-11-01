<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3.5: orders taken in training mode are marked and left out of every report and cash-up. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->boolean('is_training')->default(false)->after('status')->index());
        Schema::table('payments', fn (Blueprint $t) => $t->boolean('is_training')->default(false)->after('status'));
        Schema::table('kitchen_tickets', fn (Blueprint $t) => $t->boolean('is_training')->default(false)->after('status'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('is_training'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('is_training'));
        Schema::table('kitchen_tickets', fn (Blueprint $t) => $t->dropColumn('is_training'));
    }
};
