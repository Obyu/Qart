<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class StockDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An outlet and a variant that belong to the same business.
     *
     * @return array{0: Outlet, 1: ProductVariant}
     */
    private function scope(): array
    {
        $business = Business::factory()->create();
        $outlet = Outlet::factory()->for($business)->create();
        $variant = ProductVariant::factory()->for(
            Product::factory()->for(Category::factory()->for($business))
        )->create();

        return [$outlet, $variant];
    }

    /**
     * @return array<string, mixed>
     */
    private function ids(Outlet $outlet, ProductVariant $variant): array
    {
        return ['outlet_id' => $outlet->id, 'product_variant_id' => $variant->id];
    }

    /**
     * A stock level and one movement for the pair.
     *
     * @return array{0: Outlet, 1: ProductVariant, 2: StockLevel, 3: StockMovement}
     */
    private function withHistory(): array
    {
        [$outlet, $variant] = $this->scope();
        $level = StockLevel::factory()->create($this->ids($outlet, $variant) + ['quantity' => '5.000']);
        $movement = StockMovement::factory()->create($this->ids($outlet, $variant) + [
            'type' => StockMovementType::Opening,
            'quantity_delta' => '5.000',
            'quantity_after' => '5.000',
        ]);

        return [$outlet, $variant, $level, $movement];
    }

    /**
     * Assert that the write is rejected by the database and the error mentions $needle.
     */
    private function assertDatabaseRejects(string $needle, callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected the database to reject the write ({$needle}).");
    }

    // Factories and relationships

    public function test_default_factories_create_rows_inside_a_single_business(): void
    {
        $level = StockLevel::factory()->create();
        $movement = StockMovement::factory()->create();

        foreach ([$level, $movement] as $row) {
            $this->assertEquals(
                $row->outlet->business_id,
                $row->productVariant->product->business_id,
                'A default factory row must not mix businesses.'
            );
        }

        $this->assertSame('0.000', $level->fresh()->quantity);
        $this->assertSame(StockMovementType::Adjustment, $movement->fresh()->type);
        $this->assertSame('1.000', $movement->fresh()->quantity_delta);
        $this->assertNull($movement->fresh()->user_id);
        $this->assertNotNull($movement->fresh()->created_at);
    }

    public function test_movements_have_no_updated_at(): void
    {
        $this->assertFalse(Schema::hasColumn('stock_movements', 'updated_at'));
        $this->assertNull((new StockMovement)->getUpdatedAtColumn());
    }

    public function test_outlet_and_variant_expose_their_stock_rows(): void
    {
        [$outlet, $variant, $level, $movement] = $this->withHistory();
        StockLevel::factory()->create(); // unrelated
        StockMovement::factory()->create(); // unrelated

        $this->assertCount(1, $outlet->stockLevels);
        $this->assertTrue($outlet->stockLevels->first()->is($level));
        $this->assertCount(1, $outlet->stockMovements);
        $this->assertTrue($outlet->stockMovements->first()->is($movement));
        $this->assertTrue($variant->stockLevels->first()->is($level));
        $this->assertTrue($variant->stockMovements->first()->is($movement));
        $this->assertTrue($movement->outlet->is($outlet));
        $this->assertTrue($movement->productVariant->is($variant));
        $this->assertTrue($level->outlet->is($outlet));
        $this->assertTrue($level->productVariant->is($variant));
    }

    // Unique key

    public function test_a_stock_level_is_unique_per_outlet_and_variant(): void
    {
        [$outlet, $variant] = $this->scope();
        StockLevel::factory()->create($this->ids($outlet, $variant));

        $this->assertDatabaseRejects(
            'stock_levels_outlet_id_product_variant_id_unique',
            fn () => StockLevel::factory()->create($this->ids($outlet, $variant))
        );

        $this->assertDatabaseCount('stock_levels', 1);
    }

    public function test_the_same_variant_may_have_a_level_in_another_outlet_and_an_outlet_may_hold_other_variants(): void
    {
        [$outlet, $variant] = $this->scope();
        $otherOutlet = Outlet::factory()->for($outlet->business)->create();
        $otherVariant = ProductVariant::factory()->for($variant->product)->create();

        StockLevel::factory()->create($this->ids($outlet, $variant));
        StockLevel::factory()->create($this->ids($otherOutlet, $variant));
        StockLevel::factory()->create($this->ids($outlet, $otherVariant));

        $this->assertDatabaseCount('stock_levels', 3);
    }

    // CHECK constraints and column range

    public function test_a_negative_stock_level_is_rejected(): void
    {
        [$outlet, $variant] = $this->scope();

        $this->assertDatabaseRejects(
            'stock_levels_quantity_non_negative',
            fn () => DB::table('stock_levels')->insert($this->ids($outlet, $variant) + [
                'quantity' => '-1.000',
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    public function test_a_stock_level_cannot_be_updated_to_a_negative_value(): void
    {
        [$outlet, $variant] = $this->scope();
        $level = StockLevel::factory()->create($this->ids($outlet, $variant) + ['quantity' => '5.000']);

        $this->assertDatabaseRejects(
            'stock_levels_quantity_non_negative',
            fn () => DB::table('stock_levels')->where('id', $level->id)->update(['quantity' => '-0.001'])
        );

        $this->assertSame('5.000', $level->fresh()->quantity);
    }

    public function test_a_movement_with_a_zero_delta_is_rejected(): void
    {
        [$outlet, $variant] = $this->scope();

        $this->assertDatabaseRejects(
            'stock_movements_quantity_delta_non_zero',
            fn () => StockMovement::factory()->create($this->ids($outlet, $variant) + [
                'quantity_delta' => '0.000',
                'quantity_after' => '5.000',
            ])
        );

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_movement_with_a_negative_quantity_after_is_rejected(): void
    {
        [$outlet, $variant] = $this->scope();

        $this->assertDatabaseRejects(
            'stock_movements_quantity_after_non_negative',
            fn () => StockMovement::factory()->create($this->ids($outlet, $variant) + [
                'quantity_delta' => '-1.000',
                'quantity_after' => '-1.000',
            ])
        );

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_a_movement_may_bring_the_balance_down_to_exactly_zero(): void
    {
        [$outlet, $variant] = $this->scope();

        $movement = StockMovement::factory()->create($this->ids($outlet, $variant) + [
            'quantity_delta' => '-1.000',
            'quantity_after' => '0.000',
        ]);

        $this->assertSame('0.000', $movement->fresh()->quantity_after);
    }

    public function test_a_movement_type_outside_the_enum_is_rejected(): void
    {
        [$outlet, $variant] = $this->scope();

        $this->assertDatabaseRejects(
            'stock_movements_type_valid',
            fn () => DB::table('stock_movements')->insert($this->ids($outlet, $variant) + [
                'type' => 'invalid_type',
                'quantity_delta' => '-1.000',
                'quantity_after' => '4.000',
                'created_at' => now(),
            ])
        );
    }

    public function test_a_sale_movement_type_is_accepted(): void
    {
        [$outlet, $variant] = $this->scope();
        StockLevel::factory()->create($this->ids($outlet, $variant) + ['quantity' => '4.000']);

        DB::table('stock_movements')->insert($this->ids($outlet, $variant) + [
            'type' => StockMovementType::Sale->value,
            'quantity_delta' => '-1.000',
            'quantity_after' => '4.000',
            'created_at' => now(),
        ]);

        $this->assertSame('sale', StockMovementType::Sale->value);
        $this->assertDatabaseHas('stock_movements', $this->ids($outlet, $variant) + [
            'type' => 'sale',
        ]);
    }

    public function test_quantities_keep_three_decimals_up_to_the_column_maximum(): void
    {
        [$outlet, $variant] = $this->scope();
        $level = StockLevel::factory()->create($this->ids($outlet, $variant) + ['quantity' => '99999999999.999']);

        $this->assertSame('99999999999.999', $level->fresh()->quantity);

        $this->assertDatabaseRejects(
            'Out of range',
            fn () => DB::table('stock_levels')->where('id', $level->id)->update(['quantity' => '100000000000.000'])
        );
    }

    // Foreign keys

    public function test_foreign_keys_have_the_expected_delete_rules(): void
    {
        $rules = collect(DB::select(
            'select table_name as tbl, constraint_name as name, delete_rule as rule
             from information_schema.referential_constraints
             where constraint_schema = ? and table_name in (?, ?)',
            [DB::connection()->getDatabaseName(), 'stock_levels', 'stock_movements']
        ))->mapWithKeys(fn ($row) => [$row->name => $row->rule])->all();

        $this->assertEquals([
            'stock_levels_outlet_id_foreign' => 'CASCADE',
            'stock_levels_product_variant_id_foreign' => 'CASCADE',
            'stock_movements_outlet_id_foreign' => 'RESTRICT',
            'stock_movements_product_variant_id_foreign' => 'RESTRICT',
            'stock_movements_user_id_foreign' => 'SET NULL',
            'stock_movements_order_item_id_foreign' => 'RESTRICT',
        ], $rules);
    }

    public function test_deleting_a_user_keeps_the_movement_and_clears_user_id(): void
    {
        // A user who owns a business cannot be deleted (businesses.user_id is
        // RESTRICT), so the actor here is a user without a business.
        $user = User::factory()->create();
        [$outlet, $variant] = $this->scope();
        $movement = StockMovement::factory()->create($this->ids($outlet, $variant) + ['user_id' => $user->id]);

        $user->delete();

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertNull($movement->fresh()->user_id);
        $this->assertNull($movement->fresh()->user);
    }

    public function test_an_outlet_with_movement_history_cannot_be_deleted(): void
    {
        [$outlet, , $level, $movement] = $this->withHistory();

        $this->assertDatabaseRejects('foreign key constraint fails', fn () => $outlet->delete());

        $this->assertDatabaseHas('outlets', ['id' => $outlet->id]);
        $this->assertDatabaseHas('stock_levels', ['id' => $level->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_a_variant_with_movement_history_cannot_be_deleted(): void
    {
        [, $variant, $level, $movement] = $this->withHistory();

        $this->assertDatabaseRejects('foreign key constraint fails', fn () => $variant->delete());

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('stock_levels', ['id' => $level->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_a_product_whose_variant_has_movement_history_cannot_be_deleted(): void
    {
        [, $variant, , $movement] = $this->withHistory();
        $product = $variant->product;

        $this->assertDatabaseRejects('foreign key constraint fails', fn () => $product->delete());

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_deleting_an_outlet_without_movements_removes_its_stock_levels(): void
    {
        [$outlet, $variant] = $this->scope();
        StockLevel::factory()->create($this->ids($outlet, $variant));

        $outlet->delete();

        $this->assertDatabaseCount('stock_levels', 0);
    }

    public function test_deleting_a_variant_without_movements_removes_its_stock_levels(): void
    {
        [$outlet, $variant] = $this->scope();
        StockLevel::factory()->create($this->ids($outlet, $variant));

        $variant->delete();

        $this->assertDatabaseCount('stock_levels', 0);
    }

    // Immutability at model level

    public function test_a_movement_model_cannot_be_updated(): void
    {
        [, , , $movement] = $this->withHistory();
        $movement->quantity_after = '99.000';

        try {
            $movement->save();
            $this->fail('Expected the update to be refused.');
        } catch (LogicException) {
            $this->assertSame('5.000', $movement->fresh()->quantity_after);
        }
    }

    public function test_a_movement_model_cannot_be_deleted(): void
    {
        [, , , $movement] = $this->withHistory();

        try {
            $movement->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (LogicException) {
            $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
        }
    }
}
