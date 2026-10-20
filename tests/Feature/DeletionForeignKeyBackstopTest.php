<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage B1, K1-K4: what the foreign keys protect on their own.
 *
 * Every delete here is a raw query-builder delete, so no Eloquent event, model
 * override or service guard runs. Parents with order or stock history are
 * RESTRICTed; parents without history are removed together with their CASCADE
 * children.
 */
class DeletionForeignKeyBackstopTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN_TABLES = [
        'businesses',
        'outlets',
        'categories',
        'products',
        'product_variants',
        'stock_levels',
        'stock_movements',
        'orders',
        'order_items',
    ];

    // K1: a parent with history is protected

    #[DataProvider('parentsWithHistory')]
    public function test_k1_a_raw_delete_of_a_parent_with_history_is_rejected(string $case): void
    {
        [
            'table' => $table,
            'id' => $id,
            'constraint' => $constraint,
            'history' => [$historyTable, $historyId],
        ] = $this->parentWithHistory($case);
        $before = $this->rowCounts();

        try {
            DB::table($table)->where('id', $id)->delete();
            $this->fail('Expected the foreign key to reject the delete.');
        } catch (QueryException $e) {
            $this->assertForeignKeyViolation($e, $constraint);
        }

        $this->assertDatabaseHas($table, ['id' => $id]);
        $this->assertDatabaseHas($historyTable, ['id' => $historyId]);
        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parentsWithHistory(): array
    {
        return [
            'outlet with a stock movement' => ['outlet with a stock movement'],
            'variant with a stock movement' => ['variant with a stock movement'],
            'product with a stock movement on its variant' => ['product with a stock movement on its variant'],
            'outlet with an order' => ['outlet with an order'],
            'variant with an order item' => ['variant with an order item'],
            'product with an ordered variant' => ['product with an ordered variant'],
            'business with an order' => ['business with an order'],
        ];
    }

    // K2: the delete rule of every foreign key in the domain

    public function test_k2_foreign_keys_have_the_expected_delete_rules(): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::DOMAIN_TABLES), '?'));

        $rules = collect(DB::select(
            'select table_name as tbl, constraint_name as name, delete_rule as rule
             from information_schema.referential_constraints
             where constraint_schema = ? and table_name in ('.$placeholders.')',
            [DB::connection()->getDatabaseName(), ...self::DOMAIN_TABLES]
        ))->mapWithKeys(fn ($row) => [$row->name => $row->rule])->all();

        $this->assertEquals([
            'businesses_user_id_foreign' => 'RESTRICT',
            'outlets_business_id_foreign' => 'CASCADE',
            'categories_business_id_foreign' => 'CASCADE',
            'products_business_id_foreign' => 'CASCADE',
            'products_category_id_foreign' => 'RESTRICT',
            'product_variants_product_id_foreign' => 'CASCADE',
            'orders_business_id_foreign' => 'RESTRICT',
            'orders_business_outlet_foreign' => 'RESTRICT',
            'order_items_order_id_foreign' => 'CASCADE',
            'order_items_product_variant_id_foreign' => 'RESTRICT',
            'stock_levels_outlet_id_foreign' => 'CASCADE',
            'stock_levels_product_variant_id_foreign' => 'CASCADE',
            'stock_movements_outlet_id_foreign' => 'RESTRICT',
            'stock_movements_product_variant_id_foreign' => 'RESTRICT',
            'stock_movements_user_id_foreign' => 'SET NULL',
            'stock_movements_order_item_id_foreign' => 'RESTRICT',
        ], $rules);
    }

    // K3: controls without history are removed, with their CASCADE children

    #[DataProvider('parentsWithoutHistory')]
    public function test_k3_a_raw_delete_without_history_succeeds_and_cascades(string $case): void
    {
        ['table' => $table, 'id' => $id, 'children' => $children] = $this->parentWithoutHistory($case);

        foreach ($children as $childTable => $column) {
            $this->assertGreaterThan(0, DB::table($childTable)->where($column, $id)->count());
        }

        $this->assertSame(1, DB::table($table)->where('id', $id)->delete());

        $this->assertDatabaseMissing($table, ['id' => $id]);

        foreach ($children as $childTable => $column) {
            $this->assertSame(0, DB::table($childTable)->where($column, $id)->count());
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function parentsWithoutHistory(): array
    {
        return [
            'order with items' => ['order with items'],
            'outlet with a stock level' => ['outlet with a stock level'],
            'variant with a stock level' => ['variant with a stock level'],
            'product with two variants' => ['product with two variants'],
            'business without outlets or catalog' => ['business without outlets or catalog'],
        ];
    }

    // K4: a rejected delete is atomic, so earlier CASCADE children survive

    #[DataProvider('partiallyBlockedParents')]
    public function test_k4_a_rejected_delete_leaves_the_cascade_children_untouched(string $case): void
    {
        [
            'table' => $table,
            'id' => $id,
            'cascade' => [$cascadeTable, $cascadeCount],
        ] = $this->partiallyBlockedParent($case);
        $before = $this->rowCounts();

        $this->assertSame($cascadeCount, $before[$cascadeTable]);

        try {
            DB::table($table)->where('id', $id)->delete();
            $this->fail('Expected the foreign key to reject the delete.');
        } catch (QueryException $e) {
            $this->assertForeignKeyViolation($e, null);
        }

        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function partiallyBlockedParents(): array
    {
        return [
            'product with a free and an ordered variant' => ['product with a free and an ordered variant'],
            'outlet with two stock levels and one movement' => ['outlet with two stock levels and one movement'],
        ];
    }

    // Case builders

    /**
     * A parent row that has history, and one history row that blocks its deletion.
     *
     * constraint is the foreign key MySQL names for a direct RESTRICT. It is null
     * where the delete reaches the history through a CASCADE first, because the
     * constraint reported then is an InnoDB detail this test does not pin down.
     *
     * @return array{table: string, id: int, constraint: string|null, history: array{0: string, 1: int}}
     */
    private function parentWithHistory(string $case): array
    {
        if ($case === 'business with an order') {
            return $this->businessWithOrderAndNoCatalog();
        }

        [$outlet, $variant] = $this->outletAndVariant();

        return match ($case) {
            'outlet with a stock movement' => [
                'table' => 'outlets',
                'id' => $outlet->id,
                'constraint' => 'stock_movements_outlet_id_foreign',
                'history' => ['stock_movements', $this->recordMovement($outlet, $variant)->id],
            ],
            'variant with a stock movement' => [
                'table' => 'product_variants',
                'id' => $variant->id,
                'constraint' => 'stock_movements_product_variant_id_foreign',
                'history' => ['stock_movements', $this->recordMovement($outlet, $variant)->id],
            ],
            'product with a stock movement on its variant' => [
                'table' => 'products',
                'id' => $variant->product_id,
                'constraint' => null,
                'history' => ['stock_movements', $this->recordMovement($outlet, $variant)->id],
            ],
            'outlet with an order' => [
                'table' => 'outlets',
                'id' => $outlet->id,
                'constraint' => 'orders_business_outlet_foreign',
                'history' => ['orders', $this->orderFor($outlet)->id],
            ],
            'variant with an order item' => [
                'table' => 'product_variants',
                'id' => $variant->id,
                'constraint' => 'order_items_product_variant_id_foreign',
                'history' => ['order_items', $this->orderItemFor($outlet, $variant)->id],
            ],
            'product with an ordered variant' => [
                'table' => 'products',
                'id' => $variant->product_id,
                'constraint' => null,
                'history' => ['order_items', $this->orderItemFor($outlet, $variant)->id],
            ],
        };
    }

    /**
     * A parent row without any order or stock history.
     *
     * children maps each CASCADE child table to the column that points at the parent.
     *
     * @return array{table: string, id: int, children: array<string, string>}
     */
    private function parentWithoutHistory(string $case): array
    {
        [$outlet, $variant] = $this->outletAndVariant();

        return match ($case) {
            'order with items' => [
                'table' => 'orders',
                'id' => $this->orderWithItems($outlet, 2)->id,
                'children' => ['order_items' => 'order_id'],
            ],
            'outlet with a stock level' => [
                'table' => 'outlets',
                'id' => $this->recordLevel($outlet, $variant)->outlet_id,
                'children' => ['stock_levels' => 'outlet_id'],
            ],
            'variant with a stock level' => [
                'table' => 'product_variants',
                'id' => $this->recordLevel($outlet, $variant)->product_variant_id,
                'children' => ['stock_levels' => 'product_variant_id'],
            ],
            'product with two variants' => [
                'table' => 'products',
                'id' => $this->siblingVariant($variant)->product_id,
                'children' => ['product_variants' => 'product_id'],
            ],
            'business without outlets or catalog' => [
                'table' => 'businesses',
                'id' => Business::factory()->create()->id,
                'children' => [],
            ],
        };
    }

    /**
     * A parent whose CASCADE can remove one child before it meets a RESTRICTed one.
     *
     * cascade is the child table that holds the CASCADE children, and how many there are.
     *
     * @return array{table: string, id: int, cascade: array{0: string, 1: int}}
     */
    private function partiallyBlockedParent(string $case): array
    {
        [$outlet, $firstVariant] = $this->outletAndVariant();
        $secondVariant = $this->siblingVariant($firstVariant);

        if ($case === 'product with a free and an ordered variant') {
            $this->orderItemFor($outlet, $secondVariant);

            return ['table' => 'products', 'id' => $firstVariant->product_id, 'cascade' => ['product_variants', 2]];
        }

        $this->recordLevel($outlet, $firstVariant);
        $this->recordLevel($outlet, $secondVariant);
        $this->recordMovement($outlet, $secondVariant);

        return ['table' => 'outlets', 'id' => $outlet->id, 'cascade' => ['stock_levels', 2]];
    }

    // Fixtures

    /**
     * An outlet and a variant that belong to the same business.
     *
     * @return array{0: Outlet, 1: ProductVariant}
     */
    private function outletAndVariant(): array
    {
        $business = Business::factory()->create();
        $outlet = Outlet::factory()->for($business)->create();
        $variant = ProductVariant::factory()->for(
            Product::factory()->for(Category::factory()->for($business))
        )->create();

        return [$outlet, $variant];
    }

    /**
     * A business whose only possible blocker is an order: it has an outlet and an
     * order, and no category, product or variant that could block the delete
     * through products.category_id (RESTRICT) instead.
     *
     * constraint stays null: MySQL may name orders_business_id_foreign or
     * orders_business_outlet_foreign, an InnoDB detail this test does not pin down.
     *
     * @return array{table: string, id: int, constraint: null, history: array{0: string, 1: int}}
     */
    private function businessWithOrderAndNoCatalog(): array
    {
        $outlet = Outlet::factory()->create();
        $order = $this->orderFor($outlet);

        $counts = $this->rowCounts();
        $this->assertSame(0, $counts['categories'] + $counts['products'] + $counts['product_variants']);

        return [
            'table' => 'businesses',
            'id' => $outlet->business_id,
            'constraint' => null,
            'history' => ['orders', $order->id],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ids(Outlet $outlet, ProductVariant $variant): array
    {
        return ['outlet_id' => $outlet->id, 'product_variant_id' => $variant->id];
    }

    private function siblingVariant(ProductVariant $variant): ProductVariant
    {
        return ProductVariant::factory()->for($variant->product)->create();
    }

    private function recordMovement(Outlet $outlet, ProductVariant $variant): StockMovement
    {
        return StockMovement::factory()->create($this->ids($outlet, $variant));
    }

    private function recordLevel(Outlet $outlet, ProductVariant $variant): StockLevel
    {
        return StockLevel::factory()->create($this->ids($outlet, $variant));
    }

    private function orderFor(Outlet $outlet): Order
    {
        return Order::factory()->create([
            'business_id' => $outlet->business_id,
            'outlet_id' => $outlet->id,
        ]);
    }

    private function orderItemFor(Outlet $outlet, ProductVariant $variant): OrderItem
    {
        return OrderItem::factory()
            ->for($this->orderFor($outlet))
            ->create(['product_variant_id' => $variant->id]);
    }

    private function orderWithItems(Outlet $outlet, int $items): Order
    {
        $order = $this->orderFor($outlet);

        OrderItem::factory()->count($items)->for($order)->create();

        return $order;
    }

    // Assertions

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (self::DOMAIN_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * MySQL reports a RESTRICTed parent delete as SQLSTATE 23000, error 1451.
     */
    private function assertForeignKeyViolation(QueryException $e, ?string $constraint): void
    {
        $this->assertSame('23000', $e->errorInfo[0] ?? null);
        $this->assertSame(1451, (int) ($e->errorInfo[1] ?? 0));
        $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());

        if ($constraint !== null) {
            $this->assertStringContainsString('CONSTRAINT `'.$constraint.'`', $e->getMessage());
        }
    }
}
