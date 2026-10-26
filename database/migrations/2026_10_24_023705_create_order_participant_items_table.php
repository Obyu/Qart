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
     * One row allocates a quantity of one order item to one participant of a split
     * by items. order_id is repeated here on purpose: both composite foreign keys
     * include it, so the participant and the item must belong to the same order.
     *
     * That the allocations of an item add up to its quantity is a rule across rows.
     * The database cannot check it, so the payment service enforces it under the
     * order lock.
     */
    public function up(): void
    {
        Schema::create('order_participant_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('order_participant_id');
            $table->foreignId('order_item_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();

            // One allocation per participant and item; change its quantity instead of repeating it.
            $table->unique(
                ['order_participant_id', 'order_item_id'],
                'participant_items_participant_order_item_unique'
            );

            // Leading order_id columns that back the composite foreign keys below.
            $table->index(['order_id', 'order_participant_id'], 'participant_items_order_participant_index');
            $table->index(['order_id', 'order_item_id'], 'participant_items_order_item_index');

            // The participant must belong to this order. Removing a participant removes its allocations.
            $table->foreign(['order_id', 'order_participant_id'], 'order_participant_items_participant_foreign')
                ->references(['order_id', 'id'])
                ->on('order_participants')
                ->cascadeOnDelete();

            // The item must belong to this order. An allocated item cannot be deleted.
            $table->foreign(['order_id', 'order_item_id'], 'order_participant_items_item_foreign')
                ->references(['order_id', 'id'])
                ->on('order_items')
                ->restrictOnDelete();
        });

        DB::statement('ALTER TABLE order_participant_items ADD CONSTRAINT participant_items_quantity_in_range CHECK (quantity BETWEEN 1 AND 1000000)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_participant_items');
    }
};
