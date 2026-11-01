<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Phase 1.1: floor plan sections, table positions/shapes, merged tables and merged orders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_sections', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('name', 50);
            $table->string('color', 9)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['branch_id', 'name']);
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->foreignUuid('section_id')->nullable()->after('name')->constrained('floor_sections')->nullOnDelete();
            $table->string('shape', 10)->default('square')->after('seats');          // square | round | rect
            $table->unsignedTinyInteger('width')->default(2)->after('pos_y');         // grid cells
            $table->unsignedTinyInteger('height')->default(2)->after('width');
            $table->foreignUuid('merged_into_id')->nullable()->after('height')->constrained('dining_tables')->nullOnDelete();
        });

        // Turn the old free-text "section" into real sections, then drop the text column.
        $rows = DB::table('dining_tables')->whereNotNull('section')->get(['id', 'tenant_id', 'branch_id', 'section']);
        $sections = [];
        foreach ($rows as $row) {
            $key = $row->tenant_id.'|'.$row->branch_id.'|'.$row->section;
            if (! isset($sections[$key])) {
                $sections[$key] = (string) Str::uuid7();
                DB::table('floor_sections')->insert([
                    'id' => $sections[$key], 'tenant_id' => $row->tenant_id, 'branch_id' => $row->branch_id,
                    'name' => $row->section, 'sort_order' => count($sections), 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('dining_tables')->where('id', $row->id)->update(['section_id' => $sections[$key]]);
        }

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('merged_into_id')->nullable()->after('table_id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->string('section', 50)->nullable();
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn(['shape', 'width', 'height']);
        });
        Schema::dropIfExists('floor_sections');
    }
};
