<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Complete Edition E9.3: when a customer's personal details were removed (on request, or after the retention period). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('anonymised_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('personal_data_removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('anonymised_at'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('personal_data_removed_at'));
    }
};
