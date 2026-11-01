<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who we buy from. Shared by every branch of the restaurant.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->businessColumns(branchNullable: true);
            $table->string('name', 100);
            $table->string('contact_name', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('payment_terms', 60)->nullable();   // "Cash on delivery", "30 days"
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->foreignUuid('supplier_id')->nullable()->after('is_active')->constrained('suppliers')->nullOnDelete();
            $table->string('category', 40)->nullable()->after('code');      // Dry goods, Meat & fish, Drinks…
            $table->string('pack_name', 30)->nullable()->after('unit');     // "Bag", "Crate", "Carton"
            $table->decimal('pack_quantity', 12, 3)->nullable()->after('pack_name'); // units per pack, e.g. 25 (kg)
        });

        // Menu items with "count stock" ticked (drinks, packaged goods) keep a simple count here.
        Schema::table('menu_items', function (Blueprint $table) {
            $table->decimal('stock_qty', 12, 3)->default(0)->after('track_stock');
            $table->decimal('stock_reorder_level', 12, 3)->default(0)->after('stock_qty');
        });

        // Every change to a stock level, in or out, with who, when and why. Never edited, only added to.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('ingredient_id')->nullable()->constrained('ingredients')->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('type', 15);                         // purchase | sale | void | adjustment | wastage | stock_take | opening
            $table->decimal('quantity', 12, 3);                 // + in, − out
            $table->decimal('on_hand_after', 12, 3);
            $table->unsignedInteger('unit_cost')->nullable();   // pesewas, for purchases and valuation
            $table->string('reason', 160)->nullable();
            $table->string('reference_type', 40)->nullable();   // purchases | orders | stock_takes
            $table->uuid('reference_id')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('happened_at');

            $table->index(['branch_id', 'ingredient_id', 'happened_at']);
            $table->index(['branch_id', 'item_id', 'happened_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Purchase orders that become goods received.
        Schema::create('purchases', function (Blueprint $table) {
            $table->businessColumns();
            $table->unsignedInteger('number');
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('status', 12)->default('draft');     // draft | ordered | received | cancelled
            $table->date('expected_on')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 60)->nullable();        // supplier invoice / waybill number
            $table->unsignedInteger('total')->default(0);       // pesewas, of what was received (or ordered while open)
            $table->string('notes', 255)->nullable();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('quantity_ordered', 12, 3)->default(0);
            $table->decimal('quantity_received', 12, 3)->nullable();
            $table->unsignedInteger('unit_cost')->default(0);   // pesewas per unit
            $table->unsignedInteger('line_total')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        // A count sheet: expected vs counted, differences posted when completed.
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->businessColumns();
            $table->unsignedInteger('number');
            $table->string('status', 12)->default('open');      // open | completed | cancelled
            $table->string('category', 40)->nullable();         // null = everything
            $table->timestamp('started_at');
            $table->foreignUuid('started_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('completed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('variance_value')->default(0);      // pesewas, counted − expected at cost
            $table->string('notes', 255)->nullable();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('stock_take_lines', function (Blueprint $table) {
            $table->businessColumns();
            $table->foreignUuid('stock_take_id')->constrained('stock_takes')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->nullable()->constrained('ingredients')->cascadeOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('unit', 10);
            $table->decimal('expected', 12, 3)->default(0);
            $table->decimal('counted', 12, 3)->nullable();
            $table->unsignedInteger('unit_cost')->default(0);
            $table->foreignUuid('counted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['stock_take_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('stock_movements');
        Schema::table('menu_items', fn (Blueprint $t) => $t->dropColumn(['stock_qty', 'stock_reorder_level']));
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['category', 'pack_name', 'pack_quantity']);
        });
        Schema::dropIfExists('suppliers');
    }
};
