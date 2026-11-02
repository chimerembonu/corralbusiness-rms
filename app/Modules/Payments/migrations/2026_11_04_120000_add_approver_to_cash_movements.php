<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Security review M3: a pay-out above the Till-settings limit needs a manager PIN; the movement remembers who approved it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', fn (Blueprint $t) => $t->foreignUuid('approved_by_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('approved_by_id');
        });
    }
};
