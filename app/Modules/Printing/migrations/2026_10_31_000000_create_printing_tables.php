<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Printers configured per branch (CLAUDE.md §3.8).
        Schema::create('printers', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('name', 80);
            $table->string('driver', 20);                     // network | usb | browser
            $table->string('address', 120)->nullable();       // host or host:port, CUPS queue name, or empty for browser
            $table->unsignedSmallInteger('paper_width')->default(80); // mm: 58 or 80
            $table->boolean('prints_receipts')->default(false);
            $table->boolean('prints_kitchen_tickets')->default(false);
            $table->boolean('has_cash_drawer')->default(false);
            $table->json('station_ids')->nullable();          // Phase 1.4: which kitchen stations print here
            $table->boolean('is_active')->default(true);
        });

        // Every print is a queued job with retries; failures show on the "Printer problems" banner.
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('printer_id')->constrained('printers')->cascadeOnDelete();
            $table->string('type', 20);                       // receipt | kitchen_ticket | test | drawer
            $table->string('status', 20)->default('queued');  // queued | printing | printed | failed | waiting_browser
            $table->json('document');                         // the lines to print (driver-independent)
            $table->string('reference_type', 60)->nullable(); // e.g. order / kitchen_ticket
            $table->uuid('reference_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->index(['branch_id', 'status']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('printers');
    }
};
