<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Restaurant tables (floor plan positions are used from Phase 1.1).
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->businessColumns();
            $table->string('name', 30);
            $table->string('section', 50)->nullable();
            $table->unsignedTinyInteger('seats')->default(4);
            $table->unsignedSmallInteger('pos_x')->nullable();
            $table->unsignedSmallInteger('pos_y')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['branch_id', 'name']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->businessColumns();
            $table->date('business_date');                       // the trading day (for Z reports)
            $table->unsignedInteger('number');                   // 1, 2, 3… per branch per business day
            $table->string('type', 15);                          // dine_in | takeaway | delivery
            $table->string('status', 15)->default('open');       // open | sent | paid | void
            $table->foreignUuid('table_id')->nullable()->constrained('dining_tables')->nullOnDelete();
            $table->unsignedTinyInteger('covers')->default(1);
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->foreignUuid('opened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            // Money (pesewas). subtotal = items as entered; total = what the customer pays.
            $table->unsignedInteger('subtotal')->default(0);
            $table->string('discount_type', 10)->nullable();     // percent | fixed
            $table->unsignedInteger('discount_value')->default(0); // basis points for percent, pesewas for fixed
            $table->string('discount_reason', 120)->nullable();
            $table->unsignedInteger('discount_total')->default(0);
            $table->unsignedInteger('service_charge')->default(0);
            $table->unsignedInteger('tax_total')->default(0);
            $table->json('tax_lines')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('paid_total')->default(0);
            $table->boolean('prices_include_tax')->default(false);
            $table->timestamp('opened_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->foreignUuid('voided_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unique(['branch_id', 'business_date', 'number']);
            $table->index(['branch_id', 'business_date', 'status']);
            $table->index(['branch_id', 'status', 'table_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('menu_items')->nullOnDelete();
            $table->foreignUuid('variant_id')->nullable()->constrained('menu_item_variants')->nullOnDelete();
            $table->string('name', 120);                         // snapshot: menu can change later
            $table->string('variant_name', 60)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price');               // item/size price
            $table->unsignedInteger('modifiers_total')->default(0); // per unit
            $table->unsignedInteger('line_subtotal')->default(0);   // qty × (unit + modifiers)
            $table->unsignedInteger('discount_amount')->default(0);
            $table->boolean('taxable')->default(true);
            $table->json('tax_exempt_codes')->nullable();
            $table->json('tax_lines')->nullable();
            $table->unsignedInteger('tax_total')->default(0);
            $table->unsignedInteger('line_total')->default(0);      // what the customer pays for this line
            $table->string('status', 10)->default('pending');       // pending | sent | voided
            $table->unsignedTinyInteger('seat')->nullable();
            $table->unsignedTinyInteger('course')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->foreignUuid('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['order_id', 'status']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('modifier_id')->nullable()->constrained('menu_modifiers')->nullOnDelete();
            $table->string('group_name', 80);
            $table->string('name', 80);
            $table->unsignedInteger('unit_price')->default(0);
            $table->unsignedSmallInteger('quantity')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('dining_tables');
    }
};
