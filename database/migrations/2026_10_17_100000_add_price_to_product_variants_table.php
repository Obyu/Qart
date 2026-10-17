<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 1 of variant pricing: the column is nullable so existing variants can
     * be priced through the application. A later, separate migration makes it NOT NULL
     * once every variant has a real price.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('price', 14, 2)->nullable()->after('sku');
        });

        // NULL passes a CHECK, so unpriced variants stay valid until the final migration.
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_non_negative CHECK (price >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The CHECK references the column, so it must be dropped first.
        DB::statement('ALTER TABLE product_variants DROP CHECK product_variants_price_non_negative');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
