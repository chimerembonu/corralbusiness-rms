<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E9.6 review H1 — a second fingerprint per audit entry (core_hash) that leaves out personal details, and
 * a chain link that covers both. A forged "personal details removed" record can then excuse nothing but
 * personal details.
 *
 * The link formula changes, so the chain is sealed again from the start by the next `rms:audit:seal`. This
 * only clears the chain's own fingerprints (never an audit entry), and the tamper-evident log (E9.4) has
 * not yet been used at any restaurant: it exists only on the complete-edition branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->char('core_hash', 64)->nullable()->after('content_hash');
        });

        DB::table('activity_log')->whereNotNull('chain_seq')->update(['chain_seq' => null, 'content_hash' => null, 'chain_hash' => null]);
        DB::table('audit_chain')->delete();
        DB::table('audit_redactions')->delete();
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('core_hash');
        });
    }
};
