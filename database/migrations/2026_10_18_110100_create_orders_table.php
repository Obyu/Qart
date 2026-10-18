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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('outlet_id');
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->restrictOnDelete();

            // The pair must exist in outlets, so the outlet always belongs to the order's business.
            $table->foreign(['business_id', 'outlet_id'], 'orders_business_outlet_foreign')
                ->references(['business_id', 'id'])
                ->on('outlets')
                ->restrictOnDelete();
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('open', 'completed'))");

        // Both sides are 0/1: completed_at is set exactly when the order is completed.
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_completed_at_matches_status CHECK ((status = 'completed') = (completed_at IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
