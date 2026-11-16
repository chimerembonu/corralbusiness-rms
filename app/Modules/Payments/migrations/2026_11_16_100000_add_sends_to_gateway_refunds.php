<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How often a refund was sent to the provider, and when last (test report 9 Nov 2026, H3). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_refunds', function (Blueprint $table) {
            $table->unsignedSmallInteger('sends')->default(0)->after('checks');
            $table->timestamp('last_sent_at')->nullable()->after('sends');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_refunds', fn (Blueprint $table) => $table->dropColumn(['sends', 'last_sent_at']));
    }
};
