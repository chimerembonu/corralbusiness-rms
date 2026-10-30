<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tills (POS devices) per branch. Cashier sessions and cash-ups attach here in Phase 1.6.
        Schema::create('tills', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('name', 50);
            $table->string('code', 20);
            $table->foreignUuid('receipt_printer_id')->nullable()->constrained('printers')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tills');
    }
};
