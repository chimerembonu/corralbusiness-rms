<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1.2: promo codes, item discounts, service charge, tips, courses, refunds, split orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);       // tenant-wide unless a branch is set
            $table->string('code', 30);
            $table->string('name', 80);
            $table->string('type', 10);                           // percent | fixed
            $table->unsignedInteger('value');                     // basis points or pesewas
            $table->unsignedInteger('min_order')->default(0);     // pesewas, on the subtotal
            $table->unsignedInteger('max_discount')->nullable();  // cap in pesewas (percent codes)
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('promo_code_id')->nullable()->after('discount_reason')->constrained('promo_codes')->nullOnDelete();
            $table->string('promo_code', 30)->nullable()->after('promo_code_id');
            $table->foreignUuid('discount_approved_by_id')->nullable()->after('promo_code')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('service_charge_bp')->default(0)->after('service_charge'); // rate applied, basis points
            $table->boolean('service_charge_waived')->default(false)->after('service_charge_bp');
            $table->unsignedInteger('tip_total')->default(0)->after('paid_total');
            $table->unsignedInteger('refunded_total')->default(0)->after('tip_total');
            $table->foreignUuid('split_from_id')->nullable()->after('merged_into_id')->constrained('orders')->nullOnDelete();
            $table->timestamp('held_at')->nullable()->after('sent_at');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('item_discount_type', 10)->nullable()->after('discount_amount');  // percent | fixed
            $table->unsignedInteger('item_discount_value')->default(0)->after('item_discount_type');
            $table->string('item_discount_reason', 120)->nullable()->after('item_discount_value');
            $table->timestamp('fired_at')->nullable()->after('sent_at');                     // course called away
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('method');     // mtn | telecel | at (Mobile Money)
            $table->unsignedInteger('tip')->default(0)->after('change_given');
            $table->timestamp('refunded_at')->nullable()->after('status');
            $table->string('refund_reason', 200)->nullable()->after('refunded_at');
            $table->foreignUuid('refunded_by_id')->nullable()->after('refund_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('kitchen_ticket_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('course')->nullable()->after('seat');
            $table->boolean('is_held')->default(false)->after('course');     // waiting for "fire"
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_ticket_items', fn (Blueprint $t) => $t->dropColumn(['course', 'is_held']));
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('refunded_by_id');
            $t->dropColumn(['provider', 'tip', 'refunded_at', 'refund_reason']);
        });
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn(['item_discount_type', 'item_discount_value', 'item_discount_reason', 'fired_at']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('promo_code_id');
            $t->dropConstrainedForeignId('discount_approved_by_id');
            $t->dropConstrainedForeignId('split_from_id');
            $t->dropColumn(['promo_code', 'service_charge_bp', 'service_charge_waived', 'tip_total', 'refunded_total', 'held_at']);
        });
        Schema::dropIfExists('promo_codes');
    }
};
