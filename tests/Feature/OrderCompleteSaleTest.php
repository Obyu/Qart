<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\OrderException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * OrderService::completeSale (Stage 2c): stock deduction and order completion in
 * one transaction.
 *
 * Sequential behaviour only. Multi-process behaviour is verified in a later
 * stage (2d) and is not claimed to be proven by these tests.
 */
class OrderCompleteSaleTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private StockService $stock;

    private User $owner;

    private User $other;

    private Business $business;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrderService;
        $this->stock = new StockService;
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->business = Business::factory()->for($this->owner)->create();
        $this->outlet = Outlet::factory()->for($this->business)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A variant inside the owner's business.
     */
    private function variant(): ProductVariant
    {
        $category = Category::factory()->for($this->business)->create();
        $product = Product::factory()->for($category)->create();

        return ProductVariant::factory()->for($product)->create();
    }

    private function open(ProductVariant $variant, string $quantity): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, $quantity);
    }

    /**
     * An open order of the owner's outlet holding the given lines, built through OrderService.
     *
     * @param  list<array{0: ProductVariant, 1: int}>  $lines
     */
    private function orderWith(array $lines): Order
    {
        $order = $this->service->createOrder($this->owner, $this->business, $this->outlet);

        foreach ($lines as [$variant, $quantity]) {
            $this->service->addItem($this->owner, $order, $variant, $quantity);
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

    private function assertOrderStillOpen(Order $order): void
    {
        $stored = Order::query()->findOrFail($order->id);

        $this->assertSame(OrderStatus::Open, $stored->status);
        $this->assertNull($stored->completed_at);
    }

    private function assertRejectedWith(string $reason, callable $operation): OrderException
    {
        try {
            $operation();
        } catch (OrderException $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail("Expected an OrderException with reason {$reason}.");
    }

    private function assertStockRejectedWith(string $reason, callable $operation): StockException
    {
        try {
            $operation();
        } catch (StockException $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail("Expected a StockException with reason {$reason}.");
    }

    /**
     * @param  list<string>  $statements
     * @return list<int>
     */
    private function indexesOf(array $statements, callable $matches): array
    {
        return array_keys(array_filter($statements, $matches));
    }

    // Success

    public function test_a_sale_deducts_every_line_writes_one_negative_movement_per_item_and_completes_the_order(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $this->open($a, '10');
        $this->open($b, '5');
        $order = $this->orderWith([[$a, 3], [$b, 2]]);

        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));

        $result = $this->service->completeSale($this->owner, $order);

        $this->assertSame(OrderStatus::Completed, $result->status);
        $this->assertSame('2026-10-21 10:00:00', $result->completed_at->format('Y-m-d H:i:s'));

        $stored = Order::query()->findOrFail($order->id);
        $this->assertSame(OrderStatus::Completed, $stored->status);
        $this->assertSame('2026-10-21 10:00:00', $stored->completed_at->format('Y-m-d H:i:s'));

        $this->assertSame('7.000', $this->balance($a));
        $this->assertSame('3.000', $this->balance($b));

        $this->assertSame(2, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 4);

        foreach ([[$a, '-3.000', '7.000'], [$b, '-2.000', '3.000']] as [$variant, $delta, $after]) {
            $movement = StockMovement::query()
                ->where('type', StockMovementType::Sale->value)
                ->where('order_item_id', $this->itemOf($order, $variant)->id)
                ->sole();

            $this->assertSame($delta, $movement->quantity_delta);
            $this->assertSame($after, $movement->quantity_after);
            $this->assertSame((int) $variant->id, (int) $movement->product_variant_id);
            $this->assertSame((int) $this->outlet->id, (int) $movement->outlet_id);
            $this->assertSame((int) $this->owner->id, (int) $movement->user_id);
        }
    }

    // Authorization and lookup

    public function test_a_user_who_does_not_own_the_business_is_rejected_and_nothing_changes(): void
    {
        $a = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3]]);

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->completeSale($this->other, $order)
        );

        $this->assertOrderStillOpen($order);
        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame(0, $this->saleCount());
    }

    public function test_a_missing_order_is_reported_as_not_found(): void
    {
        $order = $this->service->createOrder($this->owner, $this->business, $this->outlet);
        Order::query()->whereKey($order->id)->delete();

        $this->assertRejectedWith(
            OrderException::REASON_NOT_FOUND,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $this->assertSame(0, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_ownership_is_checked_before_the_order_status(): void
    {
        $a = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3]]);

        $this->service->completeSale($this->owner, $order);

        // The order is completed now, but a stranger must not learn that.
        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->completeSale($this->other, $order)
        );

        // Control: the owner does get the status error for the same order.
        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeSale($this->owner, $order)
        );
    }

    // Order state

    public function test_a_repeated_completion_is_rejected_without_a_second_deduction(): void
    {
        $a = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3]]);

        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));
        $this->service->completeSale($this->owner, $order);

        Carbon::setTestNow(Carbon::parse('2026-10-21 11:30:00'));

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $stored = Order::query()->findOrFail($order->id);
        $this->assertSame(OrderStatus::Completed, $stored->status);
        $this->assertSame('2026-10-21 10:00:00', $stored->completed_at->format('Y-m-d H:i:s'));

        $this->assertSame('7.000', $this->balance($a));
        $this->assertSame(1, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_an_order_completed_with_complete_order_cannot_be_sold(): void
    {
        $a = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3]]);

        $completed = $this->service->completeOrder($this->owner, $order);

        $this->assertSame(OrderStatus::Completed, $completed->status);
        $this->assertSame('10.000', $this->balance($a), 'completeOrder() must not deduct stock.');
        $this->assertSame(0, $this->saleCount());

        $completedAt = Order::query()->findOrFail($order->id)->completed_at->toDateTimeString();

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeSale($this->owner, $completed)
        );

        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame(0, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame($completedAt, Order::query()->findOrFail($order->id)->completed_at->toDateTimeString());
    }

    public function test_an_empty_order_is_rejected(): void
    {
        $order = $this->service->createOrder($this->owner, $this->business, $this->outlet);

        $this->assertRejectedWith(
            OrderException::REASON_EMPTY_ORDER,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $this->assertOrderStillOpen($order);
        $this->assertDatabaseCount('stock_movements', 0);

        // The status check runs before the empty check: an empty order that is
        // somehow completed (forced directly in the database) reports not open.
        $forced = $this->service->createOrder($this->owner, $this->business, $this->outlet);
        Order::query()->whereKey($forced->id)->update([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now(),
        ]);

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeSale($this->owner, $forced)
        );
    }

    // Stock failures

    public function test_insufficient_stock_on_one_line_rolls_back_every_change(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $this->open($a, '10');
        $this->open($b, '1');
        $order = $this->orderWith([[$a, 3], [$b, 2]]);

        $e = $this->assertStockRejectedWith(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $this->assertSame((int) $b->id, $e->productVariantId);

        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame('1.000', $this->balance($b));
        $this->assertSame(0, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertOrderStillOpen($order);
    }

    public function test_a_missing_stock_level_is_not_created_and_the_order_stays_open(): void
    {
        $a = $this->variant();
        $c = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3], [$c, 2]]);

        $e = $this->assertStockRejectedWith(
            StockException::REASON_INSUFFICIENT_STOCK,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $this->assertSame((int) $c->id, $e->productVariantId);

        $this->assertNull($this->balance($c));
        $this->assertDatabaseCount('stock_levels', 1);
        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame(0, $this->saleCount());
        $this->assertOrderStillOpen($order);
    }

    // Distrust of in-memory state

    public function test_the_service_ignores_forged_in_memory_attributes(): void
    {
        $otherBusiness = Business::factory()->for($this->other)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $a = $this->variant();
        $this->open($a, '10');
        $orderA = $this->orderWith([[$a, 3]]);
        $orderB = $this->orderWith([[$a, 2]]);

        // The other user dresses order B up as one of their own.
        $forgedB = Order::query()->findOrFail($orderB->id);
        $forgedB->forceFill([
            'business_id' => $otherBusiness->id,
            'outlet_id' => $otherOutlet->id,
        ]);

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->completeSale($this->other, $forgedB)
        );

        $this->assertOrderStillOpen($orderB);
        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame(0, $this->saleCount());

        // The owner passes order A with forged business, outlet, status and timestamp.
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00'));

        $forgedA = Order::query()->findOrFail($orderA->id);
        $forgedA->forceFill([
            'business_id' => $otherBusiness->id,
            'outlet_id' => $otherOutlet->id,
            'status' => OrderStatus::Completed,
            'completed_at' => Carbon::parse('2020-01-01 00:00:00'),
        ]);

        $result = $this->service->completeSale($this->owner, $forgedA);

        $this->assertSame(OrderStatus::Completed, $result->status);
        $this->assertSame((int) $this->business->id, (int) $result->business_id);
        $this->assertSame((int) $this->outlet->id, (int) $result->outlet_id);
        $this->assertSame('2026-10-21 10:00:00', $result->completed_at->format('Y-m-d H:i:s'));

        $this->assertSame('7.000', $this->balance($a));
        $this->assertSame(0, StockLevel::query()->where('outlet_id', $otherOutlet->id)->count());
        $this->assertSame(1, $this->saleCount());
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('type', StockMovementType::Sale->value)
                ->where('outlet_id', $this->outlet->id)
                ->count()
        );
    }

    public function test_a_stale_open_model_is_rejected_when_the_database_says_completed(): void
    {
        $a = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3]]);

        $this->assertSame(OrderStatus::Open, $order->status);

        // Another process completes the order behind this copy's back.
        Order::query()->whereKey($order->id)->update([
            'status' => OrderStatus::Completed->value,
            'completed_at' => now(),
        ]);

        $this->assertSame(OrderStatus::Open, $order->status, 'The in-memory copy must still be stale.');

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame(0, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 1);
    }

    // Atomicity

    public function test_a_failure_in_the_order_updated_hook_rolls_back_the_stock_writes(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $this->open($a, '10');
        $this->open($b, '5');
        $order = $this->orderWith([[$a, 3], [$b, 2]]);

        $salesSeenByHook = null;
        Order::updated(function () use (&$salesSeenByHook): void {
            $salesSeenByHook = StockMovement::query()
                ->where('type', StockMovementType::Sale->value)
                ->count();

            throw new RuntimeException('Simulated failure after the stock writes.');
        });

        try {
            $this->service->completeSale($this->owner, $order);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure after the stock writes.', $e->getMessage());
        }

        // The hook ran after both sale movements existed, and none of it survived.
        $this->assertSame(2, $salesSeenByHook);
        $this->assertSame(0, $this->saleCount());
        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame('5.000', $this->balance($b));
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertOrderStillOpen($order);
    }

    public function test_a_failure_on_the_second_sale_movement_rolls_back_everything(): void
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
            $this->service->completeSale($this->owner, $order);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure after the first sale movement.', $e->getMessage());
        }

        $this->assertSame(2, $sales);
        $this->assertSame(0, $this->saleCount());
        $this->assertSame('10.000', $this->balance($a));
        $this->assertSame('5.000', $this->balance($b));
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertOrderStillOpen($order);
    }

    public function test_an_existing_sale_movement_is_reported_and_the_order_stays_open(): void
    {
        $a = $this->variant();
        $this->open($a, '10');
        $order = $this->orderWith([[$a, 3]]);

        // Stock was already deducted for this order, but the order was never completed.
        $this->stock->recordSale($this->owner, $order);

        $this->assertSame('7.000', $this->balance($a));
        $this->assertSame(1, $this->saleCount());

        $this->assertStockRejectedWith(
            StockException::REASON_SALE_ALREADY_RECORDED,
            fn () => $this->service->completeSale($this->owner, $order)
        );

        $this->assertOrderStillOpen($order);
        $this->assertSame('7.000', $this->balance($a));
        $this->assertSame(1, $this->saleCount());
        $this->assertDatabaseCount('stock_movements', 2);
    }

    // Statement order

    public function test_the_order_is_locked_first_and_its_status_is_updated_after_the_stock_writes(): void
    {
        $a = $this->variant();
        $b = $this->variant();
        $this->open($a, '10');
        $this->open($b, '10');
        $order = $this->orderWith([[$b, 1], [$a, 1]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service->completeSale($this->owner, $order);
        DB::disableQueryLog();

        $statements = array_map(fn (array $entry): string => $entry['query'], DB::getQueryLog());

        $this->assertNotEmpty($statements);

        $orderLocks = $this->indexesOf(
            $statements,
            fn (string $sql) => str_contains($sql, 'from `orders`') && str_contains($sql, 'for update')
        );
        $movementInserts = $this->indexesOf($statements, fn (string $sql) => str_starts_with($sql, 'insert into `stock_movements`'));
        $levelUpdates = $this->indexesOf($statements, fn (string $sql) => str_starts_with($sql, 'update `stock_levels`'));
        $orderUpdates = $this->indexesOf($statements, fn (string $sql) => str_starts_with($sql, 'update `orders`'));

        $this->assertNotEmpty($orderLocks, 'The order row must be locked.');
        $this->assertSame(
            0,
            $orderLocks[0],
            "The order lock must be the first statement, but the first statement was: {$statements[0]}"
        );

        $this->assertCount(2, $movementInserts);
        $this->assertCount(2, $levelUpdates);
        $this->assertCount(1, $orderUpdates, 'The order must be updated exactly once.');

        $this->assertGreaterThan(max($movementInserts), $orderUpdates[0]);
        $this->assertGreaterThan(max($levelUpdates), $orderUpdates[0]);

        foreach (array_slice($statements, $orderUpdates[0] + 1) as $sql) {
            $this->assertStringStartsWith('select', $sql, 'Only reads may follow the order status update.');
        }
    }
}
