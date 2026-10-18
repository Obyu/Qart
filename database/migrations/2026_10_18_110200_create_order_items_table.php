<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            // Snapshot of the catalog at the time the item was added.
            $table->string('product_name');
            $table->string('variant_name');
            $table->string('sku', 100);
            $table->decimal('unit_price', 14, 2);

            $table->unsignedInteger('quantity');
            $table->timestamps();

            // One line per variant in an order; adding it again merges the quantity.
            $table->unique(['order_id', 'product_variant_id']);
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_in_range CHECK (quantity BETWEEN 1 AND 1000000)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_non_negative CHECK (unit_price >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
