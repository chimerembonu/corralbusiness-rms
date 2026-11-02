<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Re-review: training mode lives on the till row, not in a replayable browser cookie. Cleared on day close. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tills', fn (Blueprint $t) => $t->boolean('training_mode')->default(false));
    }

    public function down(): void
    {
        Schema::table('tills', fn (Blueprint $t) => $t->dropColumn('training_mode'));
    }
};
