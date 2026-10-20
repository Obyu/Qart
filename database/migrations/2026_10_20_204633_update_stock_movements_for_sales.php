<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CHECK stock_movements_type_valid');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('order_item_id')->nullable();

            // One movement of each type per order item. NULLs (opening and
            // adjustment rows) are never considered duplicates in MySQL.
            $table->unique(['order_item_id', 'type'], 'stock_movements_order_item_type_unique');

            // RESTRICT: an order item with ledger history cannot be deleted.
            $table->foreign('order_item_id')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_valid
             CHECK (type IN ('opening', 'adjustment', 'sale'))"
        );
    }

    public function down(): void
    {
        if (DB::table('stock_movements')->where('type', 'sale')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: sale movements exist and the stock ledger is immutable.'
            );
        }

        DB::statement('ALTER TABLE stock_movements DROP CHECK stock_movements_type_valid');

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['order_item_id']);
            $table->dropUnique('stock_movements_order_item_type_unique');
            $table->dropColumn('order_item_id');
        });

        DB::statement(
            "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_valid
             CHECK (type IN ('opening', 'adjustment'))"
        );
    }
};
