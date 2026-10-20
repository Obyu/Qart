<?php

namespace Tests\Feature;

use App\Exceptions\BusinessHasStockHistoryException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Stage B1, H1-H9: how Eloquent behaves when it deletes entities with history.
 *
 * These tests record the behavior as it is today. They do not claim that every
 * behavior is desirable: H6 and H7 in particular show that Model::delete() does
 * not check how many rows it removed, and H9 that Order has no delete guard.
 */
class DeletionCharacterizationTest extends TestCase
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

    // H1: the database refuses an Eloquent delete of an entity with history

    #[DataProvider('subjectsWithHistory')]
    public function test_h1_eloquent_refuses_to_delete_an_entity_with_history(string $case): void
    {
        [$subject, $historyTable, $historyId] = $this->subjectWithHistory($case);
        $before = $this->rowCounts();

        try {
            $subject->delete();
            $this->fail('Expected the database to refuse the delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
        }

        $this->assertTrue($subject->exists);
        $this->assertDatabaseHas($subject->getTable(), [$subject->getKeyName() => $subject->getKey()]);
        $this->assertDatabaseHas($historyTable, ['id' => $historyId]);
        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function subjectsWithHistory(): array
    {
        return [
            'outlet with a stock movement' => ['outlet with a stock movement'],
            'variant with a stock movement' => ['variant with a stock movement'],
            'product with a stock movement on its variant' => ['product with a stock movement on its variant'],
            'outlet with an order' => ['outlet with an order'],
            'variant with an order item' => ['variant with an order item'],
            'product with an ordered variant' => ['product with an ordered variant'],
        ];
    }

    // H2: one ordered variant keeps its product and every sibling variant

    public function test_h2_a_product_with_one_ordered_variant_is_kept_with_all_its_variants(): void
    {
        [$outlet, $firstVariant] = $this->outletAndVariant();
        $orderedVariant = $this->siblingVariant($firstVariant);
        $lastVariant = $this->siblingVariant($firstVariant);
        $item = $this->orderItemFor($outlet, $orderedVariant);
        $product = $firstVariant->product;
        $before = $this->rowCounts();

        $this->assertSame(3, $before['product_variants']);

        try {
            $product->delete();
            $this->fail('Expected the ordered variant to block the product delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
        }

        $this->assertTrue($product->exists);
        $this->assertDatabaseHas('products', ['id' => $product->id]);

        foreach ([$firstVariant, $orderedVariant, $lastVariant] as $variant) {
            $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
        }

        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertSame($before, $this->rowCounts());
    }

    // H3: a product without history goes away with its variants and stock levels

    public function test_h3_a_product_without_history_is_deleted_with_its_variants_and_stock_levels(): void
    {
        [$outlet, $firstVariant] = $this->outletAndVariant();
        $secondVariant = $this->siblingVariant($firstVariant);
        $product = $firstVariant->product;
        $this->recordLevel($outlet, $firstVariant);
        $this->recordLevel($outlet, $secondVariant);

        $otherVariant = ProductVariant::factory()->for(Product::factory()->for($product->category))->create();
        $otherLevel = $this->recordLevel($outlet, $otherVariant);

        $this->assertTrue($product->delete());

        $this->assertFalse($product->exists);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $firstVariant->id]);
        $this->assertDatabaseMissing('product_variants', ['id' => $secondVariant->id]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_variants', ['id' => $otherVariant->id]);
        $this->assertDatabaseCount('stock_levels', 1);
        $this->assertDatabaseHas('stock_levels', ['id' => $otherLevel->id]);
    }

    // H4: a business delete blocked by order history is rolled back

    public function test_h4_a_business_delete_blocked_by_order_history_rolls_back_the_product_deletions(): void
    {
        $order = Order::factory()->create();
        $category = Category::factory()->for($order->business)->create();
        // Created before the ordered product, so they are deleted first and must be restored.
        Product::factory()->count(2)->for($category)->create();
        OrderItem::factory()->for($order)->create();

        $deletedProducts = 0;
        Product::deleted(function () use (&$deletedProducts) {
            $deletedProducts++;
        });
        $before = $this->rowCounts();
        $level = DB::transactionLevel();

        try {
            $order->business->delete();
            $this->fail('Expected the order history to block the business delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key constraint fails', $e->getMessage());
        }

        $this->assertGreaterThan(0, $deletedProducts, 'The failure must come after earlier products were deleted.');
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame($level, DB::transactionLevel());
    }

    // H5: a business delete blocked by stock history changes nothing

    public function test_h5_a_business_delete_blocked_by_stock_history_changes_nothing(): void
    {
        [$outlet, $variant] = $this->outletAndVariant();
        $business = $outlet->business;
        Outlet::factory()->for($business)->create();
        Product::factory()->count(2)->for($variant->product->category)->create();
        $this->recordMovement($outlet, $variant);

        $deletedProducts = 0;
        Product::deleted(function () use (&$deletedProducts) {
            $deletedProducts++;
        });
        $before = $this->rowCounts();
        $level = DB::transactionLevel();

        try {
            $business->delete();
            $this->fail('Expected the stock history to block the business delete.');
        } catch (BusinessHasStockHistoryException $e) {
            $this->assertSame(BusinessHasStockHistoryException::REASON_BUSINESS_HAS_STOCK_HISTORY, $e->reason);
        }

        $this->assertSame(0, $deletedProducts, 'The history guard must run before any product is deleted.');
        $this->assertTrue($business->exists);
        $this->assertSame($before, $this->rowCounts());
        $this->assertSame($level, DB::transactionLevel());
    }

    // H6: deleting an instance that was already deleted

    #[DataProvider('deletableSubjects')]
    public function test_h6_deleting_an_already_deleted_instance_is_a_silent_no_op(string $case): void
    {
        [$subject, $sibling] = $this->subjectWithSibling($case);
        $level = DB::transactionLevel();

        $this->assertTrue($subject->delete());
        $this->assertFalse($subject->exists);
        $before = $this->rowCounts();

        $this->assertNull($subject->delete());

        $this->assertFalse($subject->exists);
        $this->assertSame($before, $this->rowCounts());
        $this->assertDatabaseHas($sibling->getTable(), [$sibling->getKeyName() => $sibling->getKey()]);
        $this->assertSame($level, DB::transactionLevel());
    }

    // H7: deleting a stale instance whose row is already gone

    #[DataProvider('deletableSubjects')]
    public function test_h7_a_stale_instance_deletes_nothing_but_reports_success(string $case): void
    {
        [$subject, $sibling] = $this->subjectWithSibling($case);

        $subject->newQuery()->whereKey($subject->getKey())->delete();

        $this->assertTrue($subject->exists);
        $before = $this->rowCounts();

        $this->assertTrue($subject->delete());

        $this->assertFalse($subject->exists);
        $this->assertSame($before, $this->rowCounts());
        $this->assertDatabaseHas($sibling->getTable(), [$sibling->getKeyName() => $sibling->getKey()]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function deletableSubjects(): array
    {
        return [
            'product' => ['product'],
            'outlet' => ['outlet'],
            'order' => ['order'],
            'business without outlets or catalog' => ['business without outlets or catalog'],
        ];
    }

    // H8: the database decides, not relations that were loaded earlier

    #[DataProvider('staleRelations')]
    public function test_h8_the_database_decides_not_the_loaded_relations(string $case, ?string $expectedException): void
    {
        $subject = $this->staleSubject($case);
        $before = $this->rowCounts();

        if ($expectedException === null) {
            $this->assertTrue($subject->delete());
            $this->assertFalse($subject->exists);
            $this->assertDatabaseMissing($subject->getTable(), [$subject->getKeyName() => $subject->getKey()]);
            $this->assertSame($before['product_variants'] - 1, $this->rowCounts()['product_variants']);

            return;
        }

        try {
            $subject->delete();
            $this->fail('Expected the stored history to block the delete.');
        } catch (BusinessHasStockHistoryException|QueryException $e) {
            $this->assertInstanceOf($expectedException, $e);
        }

        $this->assertTrue($subject->exists);
        $this->assertSame($before, $this->rowCounts());
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function staleRelations(): array
    {
        return [
            'business gains stock history after it was loaded' => [
                'business gains stock history after it was loaded',
                BusinessHasStockHistoryException::class,
            ],
            'variant gains an order item after it was loaded' => [
                'variant gains an order item after it was loaded',
                QueryException::class,
            ],
            'variant loses its order item after its relation was loaded' => [
                'variant loses its order item after its relation was loaded',
                null,
            ],
        ];
    }

    // H9: deleting an order cascades its items inside the database only

    public function test_h9_deleting_an_order_cascades_its_items_inside_the_database_only(): void
    {
        [$outlet, $variant] = $this->outletAndVariant();
        $this->recordMovement($outlet, $variant);
        $this->recordLevel($outlet, $variant);
        $order = $this->orderFor($outlet);
        OrderItem::factory()->for($order)->create(['product_variant_id' => $variant->id]);
        OrderItem::factory()->for($order)->create();

        $deletedItems = 0;
        OrderItem::deleted(function () use (&$deletedItems) {
            $deletedItems++;
        });
        $before = $this->rowCounts();

        $this->assertSame(2, $before['order_items']);
        $this->assertTrue($order->delete());

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(0, $deletedItems, 'The database cascade must not fire Eloquent events.');
        $this->assertSame($before['product_variants'], $this->rowCounts()['product_variants']);
        $this->assertSame($before['outlets'], $this->rowCounts()['outlets']);
        $this->assertSame($before['businesses'], $this->rowCounts()['businesses']);
        $this->assertSame(1, $this->rowCounts()['stock_levels']);
        $this->assertSame(1, $this->rowCounts()['stock_movements']);
    }

    // Case builders

    /**
     * An entity with history, and the id of one history row that blocks its deletion.
     *
     * @return array{0: Model, 1: string, 2: int}
     */
    private function subjectWithHistory(string $case): array
    {
        [$outlet, $variant] = $this->outletAndVariant();

        return match ($case) {
            'outlet with a stock movement' => [$outlet, 'stock_movements', $this->recordMovement($outlet, $variant)->id],
            'variant with a stock movement' => [$variant, 'stock_movements', $this->recordMovement($outlet, $variant)->id],
            'product with a stock movement on its variant' => [$variant->product, 'stock_movements', $this->recordMovement($outlet, $variant)->id],
            'outlet with an order' => [$outlet, 'orders', $this->orderFor($outlet)->id],
            'variant with an order item' => [$variant, 'order_items', $this->orderItemFor($outlet, $variant)->id],
            'product with an ordered variant' => [$variant->product, 'order_items', $this->orderItemFor($outlet, $variant)->id],
        };
    }

    /**
     * Two rows of the same kind without history: the subject to delete and a sibling that must survive.
     *
     * @return array{0: Model, 1: Model}
     */
    private function subjectWithSibling(string $case): array
    {
        return match ($case) {
            'product' => Product::factory()->count(2)->for(Category::factory()->create())->create()->all(),
            'outlet' => Outlet::factory()->count(2)->create()->all(),
            'order' => Order::factory()->count(2)->create()->all(),
            'business without outlets or catalog' => Business::factory()->count(2)->create()->all(),
        };
    }

    /**
     * An instance that is loaded first and whose stored history changes afterwards.
     */
    private function staleSubject(string $case): Model
    {
        [$outlet, $variant] = $this->outletAndVariant();

        if ($case === 'business gains stock history after it was loaded') {
            $business = Business::query()->with('outlets')->findOrFail($outlet->business_id);
            $this->recordMovement($outlet, $variant);

            return $business;
        }

        if ($case === 'variant gains an order item after it was loaded') {
            $loaded = ProductVariant::query()->with('orderItems')->findOrFail($variant->id);
            $this->orderItemFor($outlet, $variant);

            return $loaded;
        }

        $item = $this->orderItemFor($outlet, $variant);
        $loaded = ProductVariant::query()->with('orderItems')->findOrFail($variant->id);
        $this->assertCount(1, $loaded->orderItems);

        $item->order->delete();

        $this->assertCount(1, $loaded->orderItems, 'The loaded relation must now be stale.');
        $this->assertDatabaseCount('order_items', 0);

        return $loaded;
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
}
