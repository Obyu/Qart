<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Outlet $outlet;

    private ProductVariant $variant;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        [$this->outlet, $this->variant] = $this->stockableFor(Business::factory()->for($this->owner)->create());
        $this->stock = app(StockService::class);
    }

    /**
     * An outlet and a variant that belong to the given business.
     *
     * @return array{0: Outlet, 1: ProductVariant}
     */
    private function stockableFor(Business $business): array
    {
        $outlet = Outlet::factory()->for($business)->create();
        $variant = ProductVariant::factory()->for(
            Product::factory()->for(Category::factory()->for($business))
        )->create();

        return [$outlet, $variant];
    }

    private function level(): ?StockLevel
    {
        return StockLevel::query()
            ->where('outlet_id', $this->outlet->id)
            ->where('product_variant_id', $this->variant->id)
            ->first();
    }

    private function assertBalance(string $expected): void
    {
        $this->assertSame($expected, $this->level()?->quantity);
    }

    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function assertStockFailure(string $reason, callable $operation, string $context = ''): void
    {
        try {
            $operation();
        } catch (StockException $e) {
            $this->assertSame($reason, $e->reason, $context);

            return;
        }

        $this->fail("Expected a StockException with reason '{$reason}'. {$context}");
    }

    // Opening stock

    public function test_opening_creates_the_level_and_one_movement(): void
    {
        $movement = $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10.500', 'Initial count');

        $this->assertTrue($movement->exists);
        $this->assertSame(StockMovementType::Opening, $movement->type);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'outlet_id' => $this->outlet->id,
            'product_variant_id' => $this->variant->id,
            'type' => 'opening',
            'quantity_delta' => '10.500',
            'quantity_after' => '10.500',
            'user_id' => $this->owner->id,
            'note' => 'Initial count',
        ]);
        $this->assertBalance('10.500');
        $this->assertDatabaseCount('stock_levels', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_notes_are_trimmed_and_blank_notes_are_stored_as_null(): void
    {
        $opening = $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '1', '  Counted  ');
        $adjustment = $this->stock->adjust($this->owner, $this->outlet, $this->variant, '1', '   ');

        $this->assertSame('Counted', $opening->fresh()->note);
        $this->assertNull($adjustment->fresh()->note);
    }

    public function test_duplicate_opening_is_rejected(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10');

        $this->assertStockFailure(
            StockException::REASON_DUPLICATE_OPENING,
            fn () => $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '5')
        );

        $this->assertBalance('10.000');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_duplicate_opening_is_still_rejected_after_the_balance_has_changed(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10');
        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '2');

        $this->assertStockFailure(
            StockException::REASON_DUPLICATE_OPENING,
            fn () => $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '5')
        );

        $this->assertBalance('12.000');
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_opening_is_allowed_once_per_outlet_and_variant_pair(): void
    {
        $otherVariant = ProductVariant::factory()->for($this->variant->product)->create();
        $otherOutlet = Outlet::factory()->for($this->outlet->business)->create();

        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '1');
        $this->stock->recordOpening($this->owner, $this->outlet, $otherVariant, '2');
        $this->stock->recordOpening($this->owner, $otherOutlet, $this->variant, '3');

        $this->assertDatabaseCount('stock_levels', 3);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertBalance('1.000');
    }

    public function test_opening_rejects_zero_and_negative_quantities(): void
    {
        $this->assertStockFailure(
            StockException::REASON_ZERO_QUANTITY,
            fn () => $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '0')
        );
        $this->assertStockFailure(
            StockException::REASON_INVALID_QUANTITY,
            fn () => $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '-1')
        );

        $this->assertNothingWritten();
    }

    // Adjustments

    public function test_a_positive_adjustment_increases_the_balance(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10');

        $movement = $this->stock->adjust($this->owner, $this->outlet, $this->variant, '2.5', 'Found extra');

        $this->assertSame(StockMovementType::Adjustment, $movement->type);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'type' => 'adjustment',
            'quantity_delta' => '2.500',
            'quantity_after' => '12.500',
            'user_id' => $this->owner->id,
            'note' => 'Found extra',
        ]);
        $this->assertBalance('12.500');
    }

    public function test_a_negative_adjustment_decreases_the_balance(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10');

        $movement = $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-4');

        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'quantity_delta' => '-4.000',
            'quantity_after' => '6.000',
        ]);
        $this->assertBalance('6.000');
    }

    public function test_an_adjustment_may_bring_the_balance_to_exactly_zero(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '3.250');

        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-3.250');

        $this->assertBalance('0.000');
    }

    public function test_an_adjustment_without_a_prior_opening_starts_from_zero(): void
    {
        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '3');

        $this->assertBalance('3.000');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_zero_adjustment_is_rejected(): void
    {
        foreach (['0', '0.0', '0.000', '-0', '-0.000', 0] as $zero) {
            $this->assertStockFailure(
                StockException::REASON_ZERO_QUANTITY,
                fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, $zero),
                'Input: '.var_export($zero, true)
            );
        }

        $this->assertNothingWritten();
    }

    public function test_an_adjustment_that_would_make_the_balance_negative_is_rejected(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '5');

        $this->assertStockFailure(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-5.001')
        );

        $this->assertBalance('5.000');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_negative_first_adjustment_leaves_no_level_behind(): void
    {
        $this->assertStockFailure(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-1')
        );

        // The level row is inserted inside the transaction, so it must be rolled back too.
        $this->assertNothingWritten();
    }

    // Quantity format and precision

    public function test_malformed_quantities_are_rejected(): void
    {
        $malformed = [
            'abc', '', ' ', '1e3', '1,5', '.5', '5.', '+5', '--1', '1.0001',
            '123456789012', "1\n", "\n1", '1 ', 1.5, 0.1,
        ];

        foreach ($malformed as $bad) {
            $context = 'Input: '.var_export($bad, true);

            $this->assertStockFailure(
                StockException::REASON_INVALID_QUANTITY,
                fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, $bad),
                $context
            );
            $this->assertStockFailure(
                StockException::REASON_INVALID_QUANTITY,
                fn () => $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, $bad),
                $context
            );
        }

        $this->assertNothingWritten();
    }

    public function test_decimal_quantities_are_exact_to_three_places(): void
    {
        // 0.1 + 0.2 is 0.30000000000000004 in floating point.
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '0.1');
        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '0.2');
        $this->assertBalance('0.300');

        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '1234567.891');
        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '0.109');
        $this->assertBalance('1234568.300');

        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-0.001');
        $this->assertBalance('1234568.299');
    }

    public function test_repeated_small_adjustments_do_not_drift(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->stock->adjust($this->owner, $this->outlet, $this->variant, '0.1');
        }

        $this->assertBalance('1.000');
    }

    public function test_integer_quantities_are_accepted(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, 5);
        $this->stock->adjust($this->owner, $this->outlet, $this->variant, -2);

        $this->assertBalance('3.000');
    }

    public function test_the_maximum_balance_is_enforced_without_a_database_error(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '99999999999.999');

        $this->assertStockFailure(
            StockException::REASON_QUANTITY_OVERFLOW,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '0.001')
        );

        $this->assertBalance('99999999999.999');
        $this->assertDatabaseCount('stock_movements', 1);

        $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-0.001');
        $this->assertBalance('99999999999.998');
    }

    public function test_notes_longer_than_255_characters_are_rejected(): void
    {
        $this->assertStockFailure(
            StockException::REASON_INVALID_NOTE,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '1', str_repeat('a', 256))
        );
        $this->assertNothingWritten();

        $longest = str_repeat('é', 255);
        $movement = $this->stock->adjust($this->owner, $this->outlet, $this->variant, '1', $longest);

        $this->assertSame($longest, $movement->fresh()->note);
    }

    // Ownership and business rules

    public function test_an_outlet_and_variant_of_different_businesses_are_rejected(): void
    {
        // Both businesses belong to the acting user, so only the business rule can reject this.
        [, $variantOfSecondBusiness] = $this->stockableFor(Business::factory()->for($this->owner)->create());

        $this->assertStockFailure(
            StockException::REASON_BUSINESS_MISMATCH,
            fn () => $this->stock->recordOpening($this->owner, $this->outlet, $variantOfSecondBusiness, '1')
        );
        $this->assertStockFailure(
            StockException::REASON_BUSINESS_MISMATCH,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $variantOfSecondBusiness, '1')
        );

        $this->assertNothingWritten();
    }

    public function test_a_variant_of_another_users_business_is_rejected_for_an_owned_outlet(): void
    {
        $stranger = User::factory()->create();
        [, $strangerVariant] = $this->stockableFor(Business::factory()->for($stranger)->create());

        $this->assertStockFailure(
            StockException::REASON_BUSINESS_MISMATCH,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $strangerVariant, '1')
        );

        $this->assertNothingWritten();
    }

    public function test_a_user_cannot_write_stock_for_a_business_they_do_not_own(): void
    {
        $stranger = User::factory()->create();

        $this->assertStockFailure(
            StockException::REASON_UNAUTHORIZED,
            fn () => $this->stock->recordOpening($stranger, $this->outlet, $this->variant, '5')
        );
        $this->assertStockFailure(
            StockException::REASON_UNAUTHORIZED,
            fn () => $this->stock->adjust($stranger, $this->outlet, $this->variant, '5')
        );

        // A consistent outlet and variant of someone else's business are refused too.
        [$strangerOutlet, $strangerVariant] = $this->stockableFor(Business::factory()->for($stranger)->create());
        $this->assertStockFailure(
            StockException::REASON_UNAUTHORIZED,
            fn () => $this->stock->adjust($this->owner, $strangerOutlet, $strangerVariant, '5')
        );

        $this->assertNothingWritten();
    }

    public function test_each_owner_can_write_stock_for_their_own_business(): void
    {
        $stranger = User::factory()->create();
        [$strangerOutlet, $strangerVariant] = $this->stockableFor(Business::factory()->for($stranger)->create());

        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '1');
        $this->stock->recordOpening($stranger, $strangerOutlet, $strangerVariant, '7');

        $this->assertBalance('1.000');
        $this->assertDatabaseHas('stock_levels', [
            'outlet_id' => $strangerOutlet->id,
            'product_variant_id' => $strangerVariant->id,
            'quantity' => '7.000',
        ]);
    }

    public function test_the_service_ignores_forged_in_memory_attributes(): void
    {
        $stranger = User::factory()->create();
        $strangerBusiness = Business::factory()->for($stranger)->create();

        // An outlet instance that claims to belong to the stranger's business (not saved).
        $forgedOutlet = Outlet::query()->findOrFail($this->outlet->id);
        $forgedOutlet->business_id = $strangerBusiness->id;
        $forgedOutlet->setRelation('business', $strangerBusiness);

        $this->assertStockFailure(
            StockException::REASON_UNAUTHORIZED,
            fn () => $this->stock->recordOpening($stranger, $forgedOutlet, $this->variant, '1')
        );

        // A variant instance that claims to belong to the stranger's product (not saved).
        [$strangerOutlet, $strangerVariant] = $this->stockableFor($strangerBusiness);
        $forgedVariant = ProductVariant::query()->findOrFail($this->variant->id);
        $forgedVariant->product_id = $strangerVariant->product_id;
        $forgedVariant->setRelation('product', $strangerVariant->product);

        $this->assertStockFailure(
            StockException::REASON_BUSINESS_MISMATCH,
            fn () => $this->stock->adjust($stranger, $strangerOutlet, $forgedVariant, '1')
        );

        $this->assertNothingWritten();
    }

    public function test_a_missing_outlet_or_variant_is_rejected(): void
    {
        $goneOutlet = Outlet::factory()->for($this->outlet->business)->create();
        $goneOutlet->delete();

        $goneVariant = ProductVariant::factory()->for($this->variant->product)->create();
        $goneVariant->delete();

        $this->assertStockFailure(
            StockException::REASON_NOT_FOUND,
            fn () => $this->stock->recordOpening($this->owner, $goneOutlet, $this->variant, '1')
        );
        $this->assertStockFailure(
            StockException::REASON_NOT_FOUND,
            fn () => $this->stock->adjust($this->owner, $this->outlet, $goneVariant, '1')
        );

        $this->assertNothingWritten();
    }

    // Atomicity

    public function test_a_failure_after_the_movement_insert_rolls_back_a_new_level_and_movement(): void
    {
        StockMovement::created(function () {
            throw new RuntimeException('simulated failure');
        });

        try {
            $this->stock->adjust($this->owner, $this->outlet, $this->variant, '5');
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertNothingWritten();
    }

    public function test_a_failure_after_the_movement_insert_leaves_an_existing_balance_untouched(): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10');

        StockMovement::created(function () {
            throw new RuntimeException('simulated failure');
        });

        try {
            $this->stock->adjust($this->owner, $this->outlet, $this->variant, '5');
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertBalance('10.000');
        $this->assertDatabaseCount('stock_movements', 1);
    }

    // Ledger consistency

    public function test_every_mutation_adds_exactly_one_movement_and_the_ledger_stays_consistent(): void
    {
        $calls = [
            fn () => $this->stock->recordOpening($this->owner, $this->outlet, $this->variant, '10'),
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '2.5'),
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-3'),
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '0.125'),
            fn () => $this->stock->adjust($this->owner, $this->outlet, $this->variant, '-1.625'),
        ];

        foreach ($calls as $index => $call) {
            $movement = $call();

            $this->assertDatabaseCount('stock_movements', $index + 1);
            $this->assertSame($this->level()->quantity, $movement->quantity_after);
        }

        // quantity_after is the running balance of the deltas, in order.
        $running = '0.000';
        $movements = StockMovement::query()
            ->where('outlet_id', $this->outlet->id)
            ->where('product_variant_id', $this->variant->id)
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $running = bcadd($running, $movement->quantity_delta, 3);
            $this->assertSame($running, $movement->quantity_after);
        }

        // The level equals the sum of all deltas, and the latest quantity_after.
        $sum = DB::table('stock_movements')
            ->where('outlet_id', $this->outlet->id)
            ->where('product_variant_id', $this->variant->id)
            ->selectRaw('SUM(quantity_delta) as total')
            ->first()
            ->total;

        $this->assertSame('8.000', $this->level()->quantity);
        $this->assertSame(0, bccomp((string) $sum, $this->level()->quantity, 3));
        $this->assertSame($this->level()->quantity, $movements->last()->quantity_after);
    }

    public function test_the_service_exposes_no_way_to_edit_or_delete_movements(): void
    {
        $methods = collect((new ReflectionClass(StockService::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['adjust', 'recordOpening'], $methods);
    }
}
