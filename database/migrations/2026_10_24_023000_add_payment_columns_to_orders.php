<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * payment_mode and bill_total are set together when the bill is started: from
     * then on the items of the order are frozen and bill_total is the amount that
     * must be paid. Both stay NULL for orders that never went through the payment
     * flow, so existing orders are not mislabelled as paid.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_mode', 20)->nullable()->after('status');
            $table->decimal('bill_total', 14, 2)->nullable()->after('payment_mode');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_mode_valid CHECK (payment_mode IS NULL OR payment_mode IN ('direct', 'split_items', 'split_equal'))");

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_bill_total_non_negative CHECK (bill_total IS NULL OR bill_total >= 0)');

        // Both sides are 0/1: the mode and the billed total are set together or not at all.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_bill_matches_payment_mode CHECK ((payment_mode IS NULL) = (bill_total IS NULL))');

        Schema::table('order_items', function (Blueprint $table) {
            // Target of the composite foreign keys that keep an allocation inside one order.
            $table->unique(['order_id', 'id'], 'order_items_order_id_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique('order_items_order_id_id_unique');
        });

        DB::statement('ALTER TABLE orders DROP CHECK orders_bill_matches_payment_mode');
        DB::statement('ALTER TABLE orders DROP CHECK orders_bill_total_non_negative');
        DB::statement('ALTER TABLE orders DROP CHECK orders_payment_mode_valid');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['bill_total', 'payment_mode']);
        });
    }
};
