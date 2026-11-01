<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3.3: indexes for the queries the load test showed running on every tap. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->index(['order_id', 'status'], 'payments_order_status_idx'));
        Schema::table('payments', fn (Blueprint $t) => $t->index(['cashier_session_id', 'method', 'status'], 'payments_session_method_idx'));
        Schema::table('time_entries', fn (Blueprint $t) => $t->index(['user_id', 'clocked_out_at'], 'time_entries_user_open_idx'));
        Schema::table('cashier_sessions', fn (Blueprint $t) => $t->index(['till_id', 'status'], 'cashier_sessions_till_status_idx'));
        Schema::table('cashier_sessions', fn (Blueprint $t) => $t->index(['user_id', 'status'], 'cashier_sessions_user_status_idx'));
        Schema::table('kitchen_tickets', fn (Blueprint $t) => $t->index(['order_id', 'status'], 'kitchen_tickets_order_status_idx'));
        Schema::table('orders', fn (Blueprint $t) => $t->index(['branch_id', 'opened_at'], 'orders_branch_opened_idx'));
        Schema::table('menu_branch_prices', fn (Blueprint $t) => $t->index(['branch_id', 'is_available'], 'menu_branch_prices_branch_avail_idx'));
        Schema::table('activity_log', fn (Blueprint $t) => $t->index(['log_name', 'created_at'], 'activity_log_name_created_idx'));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_order_status_idx'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_session_method_idx'));
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropIndex('time_entries_user_open_idx'));
        Schema::table('cashier_sessions', fn (Blueprint $t) => $t->dropIndex('cashier_sessions_till_status_idx'));
        Schema::table('cashier_sessions', fn (Blueprint $t) => $t->dropIndex('cashier_sessions_user_status_idx'));
        Schema::table('kitchen_tickets', fn (Blueprint $t) => $t->dropIndex('kitchen_tickets_order_status_idx'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_branch_opened_idx'));
        Schema::table('menu_branch_prices', fn (Blueprint $t) => $t->dropIndex('menu_branch_prices_branch_avail_idx'));
        Schema::table('activity_log', fn (Blueprint $t) => $t->dropIndex('activity_log_name_created_idx'));
    }
};
