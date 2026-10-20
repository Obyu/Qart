<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * StockService::recordSale (Stage 2b): the stock side of a completed sale.
 *
 * Sequential behaviour only. Multi-process behaviour is verified in a later
 * stage and is not claimed to be proven by these tests.
 */
class StockSaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private OrderService $orders;

    private User $owner;

    private User $other;

    private Business $business;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->orders = new OrderService;
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->business = Business::factory()->for($this->owner)->create();
        $this->outlet = Outlet::factory()->for($this->business)->create();
    }

    /**
     * A variant inside the given business.
     */
    private function variantFor(Business $business): ProductVariant
    {
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();

        return ProductVariant::factory()->for($product)->create();
    }

    private function variant(): ProductVariant
    {
        return $this->variantFor($this->business);
    }

    private function open(ProductVariant $variant, string $quantity): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, $quantity);
    }

    /**
     * An open order of the owner's outlet holding the given lines, created through OrderService.
     *
     * @param  list<array{0: ProductVariant, 1: int}>  $lines
     */
    private function orderWith(array $lines): Order
    {
        $order = $this->orders->createOrder($this->owner, $this->business, $this->outlet);

        foreach ($lines as [$variant, $quantity]) {
            $this->orders->addItem($this->owner, $order, $variant, $quantity);
        }

        return $order;
    }

    private function itemOf(Order $order, ProductVariant $variant): OrderItem
    {
        return OrderItem::query()
            ->where('order_id', $order->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();
    }

    private function balance(ProductVariant $variant, ?Outlet $outlet = null): ?string
    {
        return StockLevel::query()
            ->where('outlet_id', ($outlet ?? $this->outlet)->id)
            ->where('product_variant_id', $variant->id)
            ->first()?->quantity;
    }

    private function saleCount(): int
    {
        return StockMovement::query()->where('type', StockMovementType::Sale->value)->count();
    }

    private function assertRejectedWith(string $reason, callable $operation): StockException
    {
        try {
            $operation();
        } catch (StockException $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail("Expected a StockException with reason {$reason}.");
    }

    public function test_a_sale_deducts_every_line_and_writes_one_negative_movement_per_item(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $this->open($a, '10');
        $this->open($b, '5');
        $order = $this->orderWith([[$b, 5], [$a, 3]]);

        $movements = $this->stock->recordSale($this->owner, $order);

        $this->assertCount(2, $movements);
        $this->assertSame('7.000', $this->balance($a));
        $this->assertSame('0.000', $this->balance($b));

        $this->assertSame((int) $a->id, (int) $movements[0]->product_variant_id);
        $this->assertSame(StockMovementType::Sale, $movements[0]->type);
        $this->assertSame('-3.000', $movements[0]->quantity_delta);
        $this->assertSame('7.000', $movements[0]->quantity_after);
        $this->assertSame((int) $this->itemOf($order, $a)->id, (int) $movements[0]->order_item_id);
        $this->assertSame((int) $this->owner->id, (int) $movements[0]->user_id);
        $this->assertNull($movements[0]->note);

        $this->assertSame((int) $b->id, (int) $movements[1]->product_variant_id);
        $this->assertSame('-5.000', $movements[1]->quantity_delta);
        $this->assertSame('0.000', $movements[1]->quantity_after);
        $this->assertSame((int) $this->itemOf($order, $b)->id, (int) $movements[1]->order_item_id);

        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertSame(2, $this->saleCount());
    }

    public function test_insufficient_stock_rejects_the_whole_sale_and_writes_nothing(): void
    {
        $enough = $this->variant();
        $short = $this->variant();
        $this->open($enough, '10');
        $this->open($short, '2');
        $order = $this->orderWith([[$enough, 3], [$short, 3]]);
        $movementsBefore = StockMovement::query()->count();

        $e = $this->assertRejectedWith(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->stock->recordSale($this->owner, $order)
        );

        $this->assertSame((int) $short->id, $e->productVariantId);
        $this->assertSame('10.000', $this->balance($enough));
        $this->assertSame('2.000', $this->balance($short));
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(0, $this->saleCount());
    }

    public function test_a_missing_stock_level_counts_as_insufficient_and_is_not_created(): void
    {
        $variant = $this->variant();
        $order = $this->orderWith([[$variant, 1]]);

        $e = $this->assertRejectedWith(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->stock->recordSale($this->owner, $order)
        );

        $this->assertSame((int) $variant->id, $e->productVariantId);
        $this->assertNull($this->balance($variant));
        $this->assertDatabaseCount('stock_levels', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_balances_are_compared_exactly_to_three_decimals(): void
    {
        $variant = $this->variant();
        $this->open($variant, '2.999');
        $order = $this->orderWith([[$variant, 3]]);

        $this->assertRejectedWith(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->stock->recordSale($this->owner, $order)
        );
        $this->assertSame('2.999', $this->balance($variant));

        $this->stock->adjust($this->owner, $this->outlet, $variant, '0.001');
        $this->stock->recordSale($this->owner, $order);

        $this->assertSame('0.000', $this->balance($variant));
    }

    public function test_stock_of_other_outlets_is_not_used(): void
    {
        $variant = $this->variant();
        $elsewhere = Outlet::factory()->for($this->business)->create();
        $this->stock->recordOpening($this->owner, $elsewhere, $variant, '50');
        $order = $this->orderWith([[$variant, 1]]);

        $this->assertRejectedWith(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->stock->recordSale($this->owner, $order)
        );

        $this->assertSame('50.000', $this->balance($variant, $elsewhere));
        $this->assertNull($this->balance($variant));
    }

    public function test_an_order_without_items_is_rejected(): void
    {
        $order = $this->orderWith([]);

        $this->assertRejectedWith(
            StockException::REASON_EMPTY_SALE,
            fn () => $this->stock->recordSale($this->owner, $order)
        );

        $this->assertSame(0, $this->saleCount());
    }

    public function test_a_missing_order_is_rejected(): void
    {
        $order = $this->orderWith([[$this->variant(), 1]]);
        DB::table('orders')->where('id', $order->id)->delete();

        $this->assertRejectedWith(
            StockException::REASON_NOT_FOUND,
            fn () => $this->stock->recordSale($this->owner, $order)
        );
    }

    public function test_the_same_order_cannot_be_deducted_twice(): void
    {
        $variant = $this->variant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 4]]);

        $this->stock->recordSale($this->owner, $order);

        $this->assertRejectedWith(
            StockException::REASON_SALE_ALREADY_RECORDED,
            fn () => $this->stock->recordSale($this->owner, $order)
        );

        $this->assertSame('6.000', $this->balance($variant));
        $this->assertSame(1, $this->saleCount());
    }

    public function test_the_database_rejects_a_second_sale_movement_for_one_order_item(): void
    {
        $variant = $this->variant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 2]]);
        $this->stock->recordSale($this->owner, $order);
        $item = $this->itemOf($order, $variant);

        try {
            DB::table('stock_movements')->insert([
                'outlet_id' => $this->outlet->id,
                'product_variant_id' => $variant->id,
                'type' => 'sale',
                'quantity_delta' => '-1.000',
                'quantity_after' => '5.000',
                'order_item_id' => $item->id,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            $this->assertStringContainsString('stock_movements_order_item_type_unique', $e->getMessage());
            $this->assertSame(1, StockMovement::query()->where('order_item_id', $item->id)->count());

            return;
        }

        $this->fail('Expected the unique key to reject a second sale movement for one order item.');
    }

    public function test_an_actor_who_does_not_own_the_business_is_rejected(): void
    {
        $variant = $this->variant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 2]]);

        $this->assertRejectedWith(
            StockException::REASON_UNAUTHORIZED,
            fn () => $this->stock->recordSale($this->other, $order)
        );

        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());
    }

    public function test_an_item_whose_variant_belongs_to_another_business_is_rejected(): void
    {
        $own = $this->variant();
        $this->open($own, '10');
        $order = $this->orderWith([[$own, 1]]);

        $foreign = $this->variantFor(Business::factory()->for($this->other)->create());

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_variant_id' => $foreign->id,
            'product_name' => 'Forged',
            'variant_name' => 'Forged',
            'sku' => 'FORGED-1',
            'unit_price' => '1.00',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertRejectedWith(
            StockException::REASON_BUSINESS_MISMATCH,
            fn () => $this->stock->recordSale($this->owner, $order)
        );

        $this->assertSame('10.000', $this->balance($own));
        $this->assertSame(0, $this->saleCount());
    }

    public function test_the_database_rejects_an_order_whose_outlet_belongs_to_another_business(): void
    {
        $variant = $this->variant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 1]]);

        $foreignOutlet = Outlet::factory()->for(Business::factory()->for($this->other)->create())->create();

        try {
            DB::table('orders')->where('id', $order->id)->update(['outlet_id' => $foreignOutlet->id]);

            $this->fail('The database accepted an order whose outlet belongs to another business.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('orders_business_outlet_foreign', $exception->getMessage());
        }

        $this->assertSame(
            (int) $this->outlet->id,
            (int) DB::table('orders')->where('id', $order->id)->value('outlet_id')
        );
        $this->assertSame('10.000', $this->balance($variant));
        $this->assertSame(0, $this->saleCount());
    }

    public function test_the_service_ignores_forged_in_memory_attributes(): void
    {
        $variant = $this->variant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 4]]);

        $foreign = Business::factory()->for($this->other)->create();
        $order->business_id = $foreign->id;
        $order->outlet_id = Outlet::factory()->for($foreign)->create()->id;

        $movements = $this->stock->recordSale($this->owner, $order);

        $this->assertSame('6.000', $this->balance($variant));
        $this->assertSame((int) $this->outlet->id, (int) $movements[0]->outlet_id);
    }

    public function test_locks_are_taken_on_the_order_first_and_then_on_stock_levels_by_ascending_variant_id(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $c = $this->variant();

        foreach ([$a, $b, $c] as $variant) {
            $this->open($variant, '10');
        }

        $order = $this->orderWith([[$c, 1], [$a, 1], [$b, 1]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $movements = $this->stock->recordSale($this->owner, $order);
        DB::disableQueryLog();

        $queries = DB::getQueryLog();

        $orderLock = null;

        foreach ($queries as $index => $entry) {
            if (str_contains($entry['query'], 'from `orders`') && str_contains($entry['query'], 'for update')) {
                $orderLock = $index;

                break;
            }
        }

        $levelLocks = array_filter(
            $queries,
            fn (array $entry) => str_contains($entry['query'], 'from `stock_levels`') && str_contains($entry['query'], 'for update')
        );

        $this->assertNotNull($orderLock, 'The order row must be locked.');
        $this->assertCount(3, $levelLocks);
        $this->assertLessThan(array_key_first($levelLocks), $orderLock, 'The order lock must come before every stock level lock.');

        $this->assertSame(
            [(int) $a->id, (int) $b->id, (int) $c->id],
            array_map(fn (array $entry) => (int) $entry['bindings'][1], array_values($levelLocks))
        );

        $this->assertSame(
            [(int) $a->id, (int) $b->id, (int) $c->id],
            array_map(fn (StockMovement $movement) => (int) $movement->product_variant_id, $movements)
        );

        $this->assertDatabaseCount('stock_levels', 3);
    }

    public function test_a_failure_after_the_first_movement_rolls_the_whole_sale_back(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $this->open($a, '10');
        $this->open($b, '5');
        $order = $this->orderWith([[$a, 3], [$b, 2]]);

        $sales = 0;
        StockMovement::created(function (StockMovement $movement) use (&$sales): void {
            if ($movement->type === StockMovementType::Sale && ++$sales === 2) {
                throw new RuntimeException('Simulated failure after the first sale movement.');
            }
        });

        try {
            $this->stock->recordSale($this->owner, $order);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure after the first sale movement.', $e->getMessage());
        }

        $this->assertSame(2, $sales);
        $this->assertSame(0, $this->saleCount());
        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame('5.000', $this->balance($b));
    }
}
