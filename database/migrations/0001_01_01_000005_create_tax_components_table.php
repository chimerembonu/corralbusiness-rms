<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable tax components (VAT, NHIL, GETFund…). CLAUDE.md §3.6: never hard-coded.
        // branch_id null = applies to every branch of the tenant.
        Schema::create('tax_components', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('name', 80);
            $table->string('code', 20);
            $table->unsignedInteger('rate_basis_points'); // 15 % = 1500
            $table->string('kind', 10)->default('vat');   // vat | levy
            $table->string('base', 20)->default('net');   // net | net_plus_levies
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['tenant_id', 'branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_components');
    }
};
