<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title', 60)->nullable()->after('phone');
            $table->date('started_on')->nullable()->after('job_title');
            $table->string('photo_path')->nullable()->after('started_on');
            $table->string('notes', 255)->nullable()->after('photo_path');
        });

        // One row per clock-in; clocked_out_at stays empty while the person is at work.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);          // worked minutes once clocked out
            $table->unsignedInteger('break_minutes')->default(0);
            $table->string('source', 10)->default('clock');          // clock | pos | admin | auto
            $table->foreignUuid('till_id')->nullable()->constrained('tills')->nullOnDelete();
            $table->string('photo_path')->nullable();                // clock-in photo (Phase 5)
            $table->foreignUuid('edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edit_reason', 255)->nullable();
            $table->string('notes', 255)->nullable();

            $table->index(['branch_id', 'user_id', 'clocked_in_at']);
            $table->index(['branch_id', 'clocked_out_at']);
        });

        // A planned shift on the weekly roster. ends_at earlier than starts_at = finishes after midnight.
        Schema::create('roster_shifts', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('shift_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('role', 20)->nullable();
            $table->string('notes', 120)->nullable();

            $table->index(['branch_id', 'shift_date']);
            $table->index(['user_id', 'shift_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shifts');
        Schema::dropIfExists('time_entries');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['job_title', 'started_on', 'photo_path', 'notes']);
        });
    }
};
