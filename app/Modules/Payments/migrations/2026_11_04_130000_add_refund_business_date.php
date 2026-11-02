<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security review L3: a refund is posted to the business day it happens on (which must be open), so a
 * closed day's Z figures never change afterwards. Payments and fully refunded orders remember that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->date('refund_business_date')->nullable()->after('refunded_at')->index());
        Schema::table('orders', fn (Blueprint $t) => $t->date('refund_business_date')->nullable()->after('refunded_total')->index());
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('refund_business_date'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('refund_business_date'));
    }
};
