<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Compliance assessment: an optional buyer TIN on the receipt, so business customers can claim input VAT. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->string('customer_tin', 20)->nullable()->after('customer_phone'));
        Schema::table('customers', fn (Blueprint $t) => $t->string('tin', 20)->nullable()->after('email'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('customer_tin'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('tin'));
    }
};
