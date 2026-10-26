<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Payment schema: what the database guarantees on its own.
 *
 * Every write here is a raw query-builder write, so no model, service or policy
 * runs. Each rejection is asserted by the name of the constraint that fired, so a
 * different constraint rejecting the row for another reason cannot pass the test.
 * Rules across rows (allocations adding up to an item quantity, a payment equal to
 * its obligation) are not database rules and belong to the payment service tests.
 */
class PaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_TABLES = [
        'order_participants',
        'order_participant_items',
        'payments',
    ];

    // Orders: payment mode and bill total

    public function test_an_order_has_no_payment_mode_or_bill_total_by_default(): void
    {
        $order = $this->order()->fresh();

        $this->assertNull($order->payment_mode);
        $this->assertNull($order->bill_total);
    }

    public function test_each_payment_mode_is_accepted_with_a_bill_total(): void
    {
        foreach (['direct', 'split_items', 'split_equal'] as $mode) {
            $order = $this->order();

            $this->assertSame(1, DB::table('orders')->where('id', $order->id)->update([
                'payment_mode' => $mode,
                'bill_total' => '125.50',
            ]));

            $row = DB::table('orders')->where('id', $order->id)->first();

            $this->assertSame($mode, $row->payment_mode);
            $this->assertSame('125.50', $row->bill_total);
        }
    }

    public function test_a_zero_bill_total_is_accepted(): void
    {
        $order = $this->order();

        DB::table('orders')->where('id', $order->id)->update(['payment_mode' => 'direct', 'bill_total' => '0.00']);

        $this->assertSame('0.00', DB::table('orders')->where('id', $order->id)->value('bill_total'));
    }

    public function test_the_payment_mode_and_the_bill_total_are_valid_and_set_together(): void
    {
        $order = $this->order();
        $update = fn (array $values) => fn () => DB::table('orders')->where('id', $order->id)->update($values);

        $this->assertRejected($update(['payment_mode' => 'split', 'bill_total' => '10.00']), 'orders_payment_mode_valid');
        $this->assertRejected($update(['payment_mode' => 'direct']), 'orders_bill_matches_payment_mode');
        $this->assertRejected($update(['bill_total' => '10.00']), 'orders_bill_matches_payment_mode');
        $this->assertRejected($update(['payment_mode' => 'direct', 'bill_total' => '-0.01']), 'orders_bill_total_non_negative');

        $this->assertNull($order->fresh()->payment_mode);
    }

    // Participants

    public function test_a_participant_is_stored_with_its_obligation(): void
    {
        $order = $this->order();

        $id = $this->participant($order, 1, ['name' => 'Ana', 'total_amount' => '33.34']);

        $row = DB::table('order_participants')->where('id', $id)->first();

        $this->assertSame('Ana', $row->name);
        $this->assertSame('33.34', $row->total_amount);
        $this->assertEquals($order->id, $row->order_id);
        $this->assertEquals(1, $row->position);
    }

    public function test_a_position_is_unique_within_an_order(): void
    {
        $order = $this->order();
        $this->participant($order, 1);

        $this->assertRejected(fn () => $this->participant($order, 1), 'order_participants_order_id_position_unique');

        $this->participant($order, 2);
        $this->participant($this->order(), 1);

        $this->assertSame(2, DB::table('order_participants')->where('order_id', $order->id)->count());
    }

    public function test_a_position_must_be_between_one_and_one_hundred(): void
    {
        $order = $this->order();

        foreach ([0, 101] as $position) {
            $this->assertRejected(
                fn () => $this->participant($order, $position),
                'order_participants_position_in_range',
                "position {$position}"
            );
        }

        $this->participant($order, 1);
        $this->participant($order, 100);

        $this->assertSame(2, DB::table('order_participants')->where('order_id', $order->id)->count());
    }

    public function test_a_participant_name_cannot_be_blank(): void
    {
        $order = $this->order();

        foreach (['', '   '] as $name) {
            $this->assertRejected(
                fn () => $this->participant($order, 1, ['name' => $name]),
                'order_participants_name_not_blank',
                "name '{$name}'"
            );
        }

        $id = $this->participant($order, 1, ['name' => ' Ana ']);

        $this->assertSame(' Ana ', DB::table('order_participants')->where('id', $id)->value('name'));
    }

    public function test_a_participant_total_cannot_be_negative(): void
    {
        $order = $this->order();

        $this->assertRejected(
            fn () => $this->participant($order, 1, ['total_amount' => '-0.01']),
            'order_participants_total_amount_non_negative'
        );

        $id = $this->participant($order, 1, ['total_amount' => '0.00']);

        $this->assertSame('0.00', DB::table('order_participants')->where('id', $id)->value('total_amount'));
    }

    public function test_a_participant_needs_an_existing_order(): void
    {
        $this->assertRejected(
            fn () => DB::table('order_participants')->insert([
                'order_id' => 999_999_999,
                'position' => 1,
                'name' => 'Ana',
                'total_amount' => '10.00',
            ]),
            'order_participants_order_id_foreign'
        );
    }

    public function test_an_order_with_participants_cannot_be_deleted(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $participantId = $this->participant($order);

        $this->assertRejected(
            fn () => DB::table('orders')->where('id', $order->id)->delete(),
            'order_participants_order_id_foreign'
        );

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseHas('order_participants', ['id' => $participantId]);
    }

    // Allocations of items to participants

    public function test_an_allocation_ties_a_participant_to_an_item_of_the_same_order(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $participantId = $this->participant($order);

        $id = $this->allocation($order, $participantId, $item, ['quantity' => 2]);

        $this->assertDatabaseHas('order_participant_items', ['id' => $id, 'quantity' => 2]);
    }

    public function test_a_participant_of_another_order_cannot_be_allocated(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $foreignParticipantId = $this->participant($this->order());

        $this->assertRejected(
            fn () => $this->allocation($order, $foreignParticipantId, $item),
            'order_participant_items_participant_foreign'
        );
    }

    public function test_an_item_of_another_order_cannot_be_allocated(): void
    {
        $order = $this->order();
        $participantId = $this->participant($order);
        $foreignItem = OrderItem::factory()->create();

        $this->assertRejected(
            fn () => $this->allocation($order, $participantId, $foreignItem),
            'order_participant_items_item_foreign'
        );
    }

    public function test_an_item_is_allocated_to_a_participant_once(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $participantId = $this->participant($order);
        $this->allocation($order, $participantId, $item);

        $this->assertRejected(
            fn () => $this->allocation($order, $participantId, $item),
            'participant_items_participant_order_item_unique'
        );

        $otherParticipantId = $this->participant($order, 2);
        $this->allocation($order, $otherParticipantId, $item);

        $this->assertSame(2, DB::table('order_participant_items')->where('order_item_id', $item->id)->count());
    }

    public function test_an_allocated_quantity_must_be_between_one_and_one_million(): void
    {
        $order = $this->order();
        $participantId = $this->participant($order);

        foreach ([0, 1_000_001] as $quantity) {
            $item = OrderItem::factory()->for($order)->create();

            $this->assertRejected(
                fn () => $this->allocation($order, $participantId, $item, ['quantity' => $quantity]),
                'participant_items_quantity_in_range',
                "quantity {$quantity}"
            );
        }

        foreach ([1, 1_000_000] as $quantity) {
            $item = OrderItem::factory()->for($order)->create();
            $id = $this->allocation($order, $participantId, $item, ['quantity' => $quantity]);

            $this->assertDatabaseHas('order_participant_items', ['id' => $id, 'quantity' => $quantity]);
        }
    }

    public function test_deleting_a_participant_deletes_its_allocations(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $participantId = $this->participant($order);
        $allocationId = $this->allocation($order, $participantId, $item);

        $this->assertSame(1, DB::table('order_participants')->where('id', $participantId)->delete());

        $this->assertDatabaseMissing('order_participant_items', ['id' => $allocationId]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
    }

    public function test_an_allocated_item_cannot_be_deleted(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $participantId = $this->participant($order);
        $allocationId = $this->allocation($order, $participantId, $item);

        $this->assertRejected(
            fn () => DB::table('order_items')->where('id', $item->id)->delete(),
            'order_participant_items_item_foreign'
        );

        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseHas('order_participant_items', ['id' => $allocationId]);
    }

    // Payments: references

    public function test_a_payment_is_accepted_for_a_direct_order_and_for_a_participant(): void
    {
        $direct = $this->payment($this->order());
        $order = $this->order();
        $participantId = $this->participant($order);
        $split = $this->payment($order, ['order_participant_id' => $participantId, 'method' => 'cash']);

        $this->assertSame('pending', DB::table('payments')->where('id', $direct)->value('status'));
        $this->assertNull(DB::table('payments')->where('id', $direct)->value('order_participant_id'));
        $this->assertEquals($participantId, DB::table('payments')->where('id', $split)->value('order_participant_id'));
    }

    public function test_a_participant_of_another_order_cannot_be_paid(): void
    {
        $order = $this->order();
        $foreignParticipantId = $this->participant($this->order());

        $this->assertRejected(
            fn () => $this->payment($order, ['order_participant_id' => $foreignParticipantId]),
            'payments_order_participant_foreign'
        );
    }

    public function test_the_method_and_status_must_be_known(): void
    {
        $order = $this->order();

        $this->assertRejected(fn () => $this->payment($order, ['method' => 'card']), 'payments_method_valid');
        $this->assertRejected(fn () => $this->payment($order, ['status' => 'refunded']), 'payments_status_valid');
    }

    public function test_the_amount_must_be_positive(): void
    {
        $order = $this->order();

        foreach (['0.00', '-1.00'] as $amount) {
            $this->assertRejected(
                fn () => $this->payment($order, ['amount' => $amount]),
                'payments_amount_positive',
                "amount {$amount}"
            );
        }
    }

    // Payments: one active payment per obligation

    public function test_a_direct_obligation_has_one_active_payment(): void
    {
        $order = $this->order();
        $first = $this->payment($order);

        $this->assertRejected(fn () => $this->payment($order), 'payments_order_active_slot_unique');
        $this->assertRejected(
            fn () => $this->payment($order, $this->confirmedCash('50.00', '50.00', '0.00')),
            'payments_order_active_slot_unique'
        );

        DB::table('payments')->where('id', $first)->update($this->rejection());

        $retry = $this->payment($order);
        $this->payment($order, $this->rejection());

        $this->assertSame(3, DB::table('payments')->where('order_id', $order->id)->count());
        $this->assertSame('pending', DB::table('payments')->where('id', $retry)->value('status'));
    }

    public function test_a_participant_has_one_active_payment_and_can_pay_again_after_a_rejection(): void
    {
        $order = $this->order();
        $a = $this->participant($order, 1);
        $b = $this->participant($order, 2);

        $first = $this->payment($order, ['order_participant_id' => $a]);
        $this->payment($order, ['order_participant_id' => $b]);

        $this->assertRejected(
            fn () => $this->payment($order, ['order_participant_id' => $a]),
            'payments_order_active_slot_unique'
        );

        DB::table('payments')->where('id', $first)->update($this->rejection());
        $this->payment($order, ['order_participant_id' => $a]);

        $this->assertSame(3, DB::table('payments')->where('order_id', $order->id)->count());
    }

    public function test_the_active_slot_is_computed_by_the_database(): void
    {
        $order = $this->order();
        $participantId = $this->participant($order);
        $direct = $this->payment($this->order());
        $split = $this->payment($order, ['order_participant_id' => $participantId]);
        $rejected = $this->payment($this->order(), $this->rejection());
        $slot = fn (int $id) => DB::table('payments')->where('id', $id)->value('active_slot');

        $this->assertNotNull($slot($direct));
        $this->assertSame(0, (int) $slot($direct));
        $this->assertSame($participantId, (int) $slot($split));
        $this->assertNull($slot($rejected));

        $this->assertRejected(fn () => $this->payment($this->order(), ['active_slot' => 7]), 'active_slot');
    }

    // Payments: consistency of status, confirmation and rejection

    public function test_confirmation_fields_are_consistent(): void
    {
        $userId = User::factory()->create()->id;
        $at = now()->toDateTimeString();

        $cases = [
            'confirmed without who and when' => ['status' => 'confirmed'],
            'confirmed without who' => ['status' => 'confirmed', 'confirmed_at' => $at],
            'confirmed without when' => ['status' => 'confirmed', 'confirmed_by' => $userId],
            'pending with who and when' => ['confirmed_by' => $userId, 'confirmed_at' => $at],
            'pending with only who' => ['confirmed_by' => $userId],
        ];

        foreach ($cases as $case => $values) {
            $this->assertRejected(
                fn () => $this->payment($this->order(), $values),
                'payments_confirmation_consistent',
                $case
            );
        }

        $id = $this->payment($this->order(), $this->confirmed());

        $this->assertSame('confirmed', DB::table('payments')->where('id', $id)->value('status'));
    }

    public function test_rejection_fields_are_consistent(): void
    {
        $userId = User::factory()->create()->id;
        $at = now()->toDateTimeString();

        $cases = [
            'rejected without who and when' => ['status' => 'rejected'],
            'rejected without who' => ['status' => 'rejected', 'rejected_at' => $at],
            'rejected without when' => ['status' => 'rejected', 'rejected_by' => $userId],
            'pending with who and when' => ['rejected_by' => $userId, 'rejected_at' => $at],
            'pending with only who' => ['rejected_by' => $userId],
        ];

        foreach ($cases as $case => $values) {
            $this->assertRejected(
                fn () => $this->payment($this->order(), $values),
                'payments_rejection_consistent',
                $case
            );
        }

        $id = $this->payment($this->order(), $this->rejection());

        $this->assertSame('rejected', DB::table('payments')->where('id', $id)->value('status'));
    }

    // Payments: cash and QRIS

    public function test_cash_amounts_and_change_are_consistent(): void
    {
        $this->payment($this->order(), $this->confirmedCash('50.00', '100.00', '50.00'));
        $this->payment($this->order(), $this->confirmedCash('50.00', '50.00', '0.00'));
        $this->payment($this->order(), ['method' => 'cash']);
        $this->payment($this->order(), $this->rejection() + ['method' => 'cash']);

        $cases = [
            'received below the amount' => $this->confirmedCash('50.00', '49.99', '0.00'),
            'change too small' => $this->confirmedCash('50.00', '100.00', '49.99'),
            'change too large' => $this->confirmedCash('50.00', '100.00', '50.01'),
            'confirmed without received' => $this->confirmedCash('50.00', null, '0.00'),
            'pending with received' => ['method' => 'cash', 'amount_received' => '10.00'],
            'pending with change' => ['method' => 'cash', 'change_amount' => '1.00'],
        ];

        foreach ($cases as $case => $values) {
            $this->assertRejected(
                fn () => $this->payment($this->order(), $values),
                'payments_cash_amount_consistent',
                $case
            );
        }
    }

    public function test_qris_payments_carry_no_cash_amounts(): void
    {
        $this->assertRejected(
            fn () => $this->payment($this->order(), ['amount_received' => '10.00']),
            'payments_qris_amount_consistent'
        );
        $this->assertRejected(
            fn () => $this->payment($this->order(), ['change_amount' => '1.00']),
            'payments_qris_amount_consistent'
        );

        $id = $this->payment($this->order(), $this->confirmed());

        $this->assertSame('qris', DB::table('payments')->where('id', $id)->value('method'));
    }

    public function test_a_qris_reference_belongs_to_a_qris_payment_and_is_not_blank(): void
    {
        $id = $this->payment($this->order(), ['qris_reference' => 'BANK-REF-123']);

        $this->assertSame('BANK-REF-123', DB::table('payments')->where('id', $id)->value('qris_reference'));

        $this->assertRejected(
            fn () => $this->payment($this->order(), ['qris_reference' => '   ']),
            'payments_qris_reference_valid'
        );
        $this->assertRejected(
            fn () => $this->payment($this->order(), ['method' => 'cash', 'qris_reference' => 'BANK-REF-123']),
            'payments_qris_reference_valid'
        );
    }

    // Payments: history is never deleted

    public function test_a_payment_blocks_the_deletion_of_its_order_and_participant(): void
    {
        $direct = $this->order();
        $this->payment($direct);

        $this->assertRejected(
            fn () => DB::table('orders')->where('id', $direct->id)->delete(),
            'payments_order_id_foreign'
        );

        $order = $this->order();
        $participantId = $this->participant($order);
        $this->payment($order, ['order_participant_id' => $participantId]);

        $this->assertRejected(
            fn () => DB::table('order_participants')->where('id', $participantId)->delete(),
            'payments_order_participant_foreign'
        );

        $this->assertDatabaseHas('orders', ['id' => $direct->id]);
        $this->assertDatabaseHas('order_participants', ['id' => $participantId]);
    }

    public function test_a_payment_blocks_the_deletion_of_the_user_who_confirmed_or_rejected_it(): void
    {
        $confirmed = $this->confirmed();
        $this->payment($this->order(), $confirmed);

        $rejection = $this->rejection();
        $this->payment($this->order(), $rejection);

        $this->assertRejected(
            fn () => DB::table('users')->where('id', $confirmed['confirmed_by'])->delete(),
            'payments_confirmed_by_foreign'
        );
        $this->assertRejected(
            fn () => DB::table('users')->where('id', $rejection['rejected_by'])->delete(),
            'payments_rejected_by_foreign'
        );

        $this->assertDatabaseHas('users', ['id' => $confirmed['confirmed_by']]);
        $this->assertDatabaseHas('users', ['id' => $rejection['rejected_by']]);
    }

    // Delete rules of the new foreign keys

    public function test_the_new_foreign_keys_have_the_expected_delete_rules(): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::NEW_TABLES), '?'));

        $rules = collect(DB::select(
            'select table_name as tbl, constraint_name as name, delete_rule as rule
             from information_schema.referential_constraints
             where constraint_schema = ? and table_name in ('.$placeholders.')',
            [DB::connection()->getDatabaseName(), ...self::NEW_TABLES]
        ))->mapWithKeys(fn ($row) => [$row->name => $row->rule])->all();

        $this->assertEquals([
            'order_participants_order_id_foreign' => 'RESTRICT',
            'order_participant_items_participant_foreign' => 'CASCADE',
            'order_participant_items_item_foreign' => 'RESTRICT',
            'payments_order_id_foreign' => 'RESTRICT',
            'payments_order_participant_foreign' => 'RESTRICT',
            'payments_confirmed_by_foreign' => 'RESTRICT',
            'payments_rejected_by_foreign' => 'RESTRICT',
        ], $rules);
    }

    // Fixtures and assertions

    private function order(): Order
    {
        return Order::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function participant(Order $order, int $position = 1, array $overrides = []): int
    {
        return DB::table('order_participants')->insertGetId($overrides + [
            'order_id' => $order->id,
            'position' => $position,
            'name' => 'Participant '.$position,
            'total_amount' => '10.00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function allocation(Order $order, int $participantId, OrderItem $item, array $overrides = []): int
    {
        return DB::table('order_participant_items')->insertGetId($overrides + [
            'order_id' => $order->id,
            'order_participant_id' => $participantId,
            'order_item_id' => $item->id,
            'quantity' => 1,
        ]);
    }

    /**
     * A valid pending QRIS payment of the whole order unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function payment(Order $order, array $overrides = []): int
    {
        return DB::table('payments')->insertGetId($overrides + [
            'order_id' => $order->id,
            'order_participant_id' => null,
            'method' => 'qris',
            'status' => 'pending',
            'amount' => '50.00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmed(): array
    {
        return [
            'status' => 'confirmed',
            'confirmed_by' => User::factory()->create()->id,
            'confirmed_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rejection(): array
    {
        return [
            'status' => 'rejected',
            'rejected_by' => User::factory()->create()->id,
            'rejected_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirmedCash(string $amount, ?string $received, string $change): array
    {
        return $this->confirmed() + [
            'method' => 'cash',
            'amount' => $amount,
            'amount_received' => $received,
            'change_amount' => $change,
        ];
    }

    private function assertRejected(callable $operation, string $constraint, string $case = ''): void
    {
        try {
            $operation();
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage(), $case);

            return;
        }

        $this->fail("Expected {$constraint} to reject the write. {$case}");
    }
}
