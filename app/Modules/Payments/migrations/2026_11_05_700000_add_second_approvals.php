<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Complete Edition E9.4: the second manager who confirmed a big refund or a day close (when Till settings ask for one). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->uuid('refund_second_approved_by_id')->nullable());
        Schema::table('day_closes', fn (Blueprint $table) => $table->uuid('second_approved_by_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('refund_second_approved_by_id'));
        Schema::table('day_closes', fn (Blueprint $table) => $table->dropColumn('second_approved_by_id'));
    }
};
