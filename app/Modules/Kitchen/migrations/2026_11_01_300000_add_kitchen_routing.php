<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1.4: routing rules (category/item → station), default station, served tickets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_routes', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);     // tenant-wide rule, or a branch override
            $table->string('routable_type', 20);                // category | item
            $table->uuid('routable_id');
            $table->foreignUuid('station_id')->constrained('kitchen_stations')->cascadeOnDelete();

            $table->unique(['tenant_id', 'branch_id', 'routable_type', 'routable_id'], 'kitchen_routes_target_unique');
        });

        Schema::table('kitchen_stations', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');   // where unrouted items go
            $table->boolean('is_expo')->default(false)->after('is_default');     // pass / expo station (sees everything)
        });

        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->timestamp('served_at')->nullable()->after('done_at');        // cleared from the expo screen
            $table->index(['branch_id', 'station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'station_id', 'status']);
            $table->dropColumn('served_at');
        });
        Schema::table('kitchen_stations', fn (Blueprint $t) => $t->dropColumn(['is_default', 'is_expo']));
        Schema::dropIfExists('kitchen_routes');
    }
};
