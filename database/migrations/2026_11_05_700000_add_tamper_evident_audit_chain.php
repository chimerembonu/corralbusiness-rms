<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete Edition E9.4 — tamper-evident audit log. Each activity_log entry is sealed into a chain: its own
 * fingerprint (content_hash) and a link to the entry before it (chain_hash). Changing, removing or slipping
 * in an entry breaks the chain, which the nightly check and Settings → History check report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('chain_seq')->nullable()->unique();
            $table->char('content_hash', 64)->nullable();
            $table->char('chain_hash', 64)->nullable();
        });

        // The end of the chain (one row per store server), locked while new entries are sealed.
        Schema::create('audit_chain', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->char('last_hash', 64);
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_count')->nullable();
            $table->json('problems')->nullable();
            $table->timestamps();
        });

        // Entries whose personal details were removed on purpose (E9.3). Each removal is itself an audit entry
        // whose fingerprint covers this list, so the list cannot be added to quietly.
        Schema::create('audit_redactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('activity_id')->index();
            $table->char('old_content_hash', 64);
            $table->char('new_content_hash', 64);
            $table->unsignedBigInteger('redaction_activity_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_redactions');
        Schema::dropIfExists('audit_chain');
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropUnique(['chain_seq']);
            $table->dropColumn(['chain_seq', 'content_hash', 'chain_hash']);
        });
    }
};
