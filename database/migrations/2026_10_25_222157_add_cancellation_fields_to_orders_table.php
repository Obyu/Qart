<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
        });

        DB::statement('ALTER TABLE orders DROP CHECK orders_status_valid');
        DB::statement('ALTER TABLE orders DROP CHECK orders_completed_at_matches_status');

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_status_valid
            CHECK (status IN ('open', 'completed', 'cancelled'))
        ");

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_completed_at_matches_status
            CHECK (
                (status = 'completed' AND completed_at IS NOT NULL)
                OR
                (status IN ('open', 'cancelled') AND completed_at IS NULL)
            )
        ");

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_cancellation_consistent
            CHECK (
                (
                    status = 'cancelled'
                    AND cancelled_at IS NOT NULL
                    AND cancelled_by IS NOT NULL
                    AND cancellation_reason IS NOT NULL
                    AND CHAR_LENGTH(TRIM(cancellation_reason)) > 0
                )
                OR
                (
                    status <> 'cancelled'
                    AND cancelled_at IS NULL
                    AND cancelled_by IS NULL
                    AND cancellation_reason IS NULL
                )
            )
        ");
    }

    public function down(): void
    {
        $cancelledOrders = DB::table('orders')
            ->where('status', 'cancelled')
            ->count();

        if ($cancelledOrders > 0) {
            throw new RuntimeException(
                "Cannot roll back order cancellation migration while {$cancelledOrders} cancelled order(s) exist. Preserve cancellation history before retrying."
            );
        }

        DB::statement('ALTER TABLE orders DROP CHECK orders_cancellation_consistent');
        DB::statement('ALTER TABLE orders DROP CHECK orders_completed_at_matches_status');
        DB::statement('ALTER TABLE orders DROP CHECK orders_status_valid');

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_status_valid
            CHECK (status IN ('open', 'completed'))
        ");

        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_completed_at_matches_status
            CHECK ((status = 'completed') = (completed_at IS NOT NULL))
        ");

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
            ]);
        });
    }
};
