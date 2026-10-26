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
     * One payment settles one obligation of an order: the whole bill of a direct
     * order (no participant) or one participant of a split bill. A rejected payment
     * is kept as history and its obligation can be paid again.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id');
            $table->foreignId('order_participant_id')->nullable();

            $table->string('method', 20);
            $table->string('status', 20)->default('pending');

            $table->decimal('amount', 14, 2);
            $table->decimal('amount_received', 14, 2)->nullable();
            $table->decimal('change_amount', 14, 2)->default('0.00');

            $table->string('qris_reference', 150)->nullable();

            $table->foreignId('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();

            // Computed by the database, never written: the obligation this payment
            // occupies. A rejected payment frees it (NULL); a direct payment has no
            // participant and occupies slot 0.
            $table->unsignedBigInteger('active_slot')->nullable()->storedAs(
                "CASE WHEN status = 'rejected' THEN NULL ELSE COALESCE(order_participant_id, 0) END"
            );

            $table->timestamps();

            // At most one pending or confirmed payment per obligation of an order.
            $table->unique(['order_id', 'active_slot'], 'payments_order_active_slot_unique');

            $table->index(['order_id', 'order_participant_id'], 'payments_order_participant_index');
            $table->index(['order_id', 'status']);
            $table->index(['order_participant_id', 'status']);

            // A payment with history is never deleted, and neither is its order.
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();

            // The participant must belong to the same order. Not enforced when the
            // participant is NULL, which is exactly a direct payment.
            $table->foreign(['order_id', 'order_participant_id'], 'payments_order_participant_foreign')
                ->references(['order_id', 'id'])
                ->on('order_participants')
                ->restrictOnDelete();

            $table->foreign('confirmed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_valid CHECK (method IN ('cash', 'qris'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending', 'confirmed', 'rejected'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_received_non_negative CHECK (amount_received IS NULL OR amount_received >= 0)');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_change_non_negative CHECK (change_amount >= 0)');

        // Confirmed exactly when confirmed_at is set, and who and when come together.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_confirmation_consistent CHECK (((status = 'confirmed') = (confirmed_at IS NOT NULL)) AND ((confirmed_at IS NULL) = (confirmed_by IS NULL)))");

        // Rejected exactly when rejected_at is set, and who and when come together.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_rejection_consistent CHECK (((status = 'rejected') = (rejected_at IS NOT NULL)) AND ((rejected_at IS NULL) = (rejected_by IS NULL)))");

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_cash_amount_consistent CHECK (method <> 'cash' OR (status = 'confirmed' AND amount_received IS NOT NULL AND amount_received >= amount AND change_amount = amount_received - amount) OR (status <> 'confirmed' AND amount_received IS NULL AND change_amount = 0))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_qris_amount_consistent CHECK (method <> 'qris' OR (amount_received IS NULL AND change_amount = 0))");

        // A reference belongs to a manual QRIS payment and is never blank.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_qris_reference_valid CHECK (qris_reference IS NULL OR (method = 'qris' AND CHAR_LENGTH(TRIM(qris_reference)) > 0))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
