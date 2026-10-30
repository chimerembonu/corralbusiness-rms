<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Key/value settings. branch_id null = applies to the whole tenant; a row with a
        // branch_id overrides the tenant value for that branch.
        Schema::create('settings', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('key', 120);
            $table->json('value')->nullable();

            $table->unique(['tenant_id', 'branch_id', 'key']);
        });

        // Which modules are switched on for a tenant (feature flags, CLAUDE.md §5).
        Schema::create('module_settings', function (Blueprint $table) {
            $table->businessColumns(branch: false);
            $table->string('module', 50);
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();

            $table->unique(['tenant_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_settings');
        Schema::dropIfExists('settings');
    }
};
