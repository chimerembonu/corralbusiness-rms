<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1.3: delivery orders — address, fee, rider and delivery status. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_address', 255)->nullable()->after('customer_phone');
            $table->string('delivery_notes', 255)->nullable()->after('delivery_address');
            $table->unsignedInteger('delivery_fee')->default(0)->after('service_charge_waived');   // pesewas, added after tax
            $table->string('delivery_status', 15)->nullable()->after('delivery_fee');             // pending | assigned | out | delivered | failed
            $table->foreignUuid('rider_id')->nullable()->after('delivery_status')->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('rider_id');
            $table->timestamp('out_at')->nullable()->after('assigned_at');
            $table->timestamp('delivered_at')->nullable()->after('out_at');
            $table->string('delivery_failure', 200)->nullable()->after('delivered_at');

            $table->index(['branch_id', 'type', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'type', 'delivery_status']);
            $table->dropConstrainedForeignId('rider_id');
            $table->dropColumn(['delivery_address', 'delivery_notes', 'delivery_fee', 'delivery_status', 'assigned_at', 'out_at', 'delivered_at', 'delivery_failure']);
        });
    }
};
