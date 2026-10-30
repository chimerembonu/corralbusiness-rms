<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only log of business events for store ↔ cloud sync (CLAUDE.md §3.3).
        Schema::create('outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('branch_id')->nullable()->index();
            $table->string('event_type', 100)->index();
            $table->json('payload');
            $table->timestamp('created_at');
            $table->timestamp('synced_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
