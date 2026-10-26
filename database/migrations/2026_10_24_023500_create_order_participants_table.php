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
     * A participant is one payer of a split bill and owns one obligation, stored in
     * total_amount. position gives every participant a stable place in the order, so
     * the cents left over by an equal split always go to the same participants.
     */
    public function up(): void
    {
        Schema::create('order_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');

            // The same upper bound as Money::MAX_PARTS.
            $table->unsignedSmallInteger('position');
            $table->string('name', 100);
            $table->decimal('total_amount', 14, 2);
            $table->timestamps();

            $table->unique(['order_id', 'position'], 'order_participants_order_id_position_unique');

            // Target of the composite foreign keys that keep allocations and payments
            // inside one order.
            $table->unique(['order_id', 'id'], 'order_participants_order_id_id_unique');

            // An order with participants is never deleted: that would silently drop its bill.
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE order_participants ADD CONSTRAINT order_participants_position_in_range CHECK (position BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE order_participants ADD CONSTRAINT order_participants_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0)');
        DB::statement('ALTER TABLE order_participants ADD CONSTRAINT order_participants_total_amount_non_negative CHECK (total_amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_participants');
    }
};
