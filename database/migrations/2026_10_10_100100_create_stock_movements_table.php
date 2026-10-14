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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('quantity_delta', 14, 3);
            $table->decimal('quantity_after', 14, 3);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['outlet_id', 'product_variant_id', 'id']);
            $table->index(['outlet_id', 'created_at']);
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_valid CHECK (type IN ('opening', 'adjustment'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_delta_non_zero CHECK (quantity_delta <> 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_after_non_negative CHECK (quantity_after >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
