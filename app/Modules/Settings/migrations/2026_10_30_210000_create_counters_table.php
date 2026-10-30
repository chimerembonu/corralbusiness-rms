<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Number sequences (order numbers, receipt numbers…) per tenant/branch and optional scope (e.g. a date).
        Schema::create('counters', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('key', 50);
            $table->string('scope', 50)->nullable();
            $table->unsignedBigInteger('value')->default(0);

            $table->unique(['tenant_id', 'branch_id', 'key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
