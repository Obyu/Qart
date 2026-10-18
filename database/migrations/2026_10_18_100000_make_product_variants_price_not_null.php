<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stage 2 of variant pricing: every variant must now have a real price.
     *
     * The migration refuses to run while any variant is still unpriced. It never
     * fills in a replacement value; the real prices have to be entered first.
     */
    public function up(): void
    {
        $missing = DB::table('product_variants')->whereNull('price')->count();

        if ($missing > 0) {
            throw new RuntimeException(
                "Cannot make product_variants.price NOT NULL: {$missing} variant(s) still have no price. "
                .'Enter the real prices first. Nothing was changed.'
            );
        }

        // The CHECK (price >= 0) is a table constraint and stays in place.
        DB::statement('ALTER TABLE product_variants MODIFY price DECIMAL(14,2) NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE product_variants MODIFY price DECIMAL(14,2) NULL');
    }
};
