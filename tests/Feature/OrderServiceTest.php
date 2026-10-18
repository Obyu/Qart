<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\OrderException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Sequential behaviour of OrderService (Phase 1).
 *
 * Database constraints and OrderPolicy are covered by OrderDatabaseTest and
 * OrderPolicyTest. Multi-process concurrency is not covered here: it is verified
 * in a later phase and is not claimed to be proven by these tests.
 */
class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private User $owner;

    private User $other;

    private Business $business;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrderService;
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->business = Business::factory()->for($this->owner)->create();
        $this->outlet = Outlet::factory()->for($this->business)->create();
    }

    /**
     * A variant inside the given business.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function variantFor(Business $business, array $attributes = []): ProductVariant
    {
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();

        return ProductVariant::factory()->for($product)->create($attributes);
    }

    /**
     * An open order of the owner's business, created through the service.
     */
    private function order(): Order
    {
        return $this->service->createOrder($this->owner, $this->business, $this->outlet);
    }

    private function assertRejectedWith(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (OrderException $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail("Expected an OrderException with reason {$reason}.");
    }

    // createOrder

    public function test_the_owner_creates_an_open_order_from_database_values(): void
    {
        $order = $this->service->createOrder($this->owner, $this->business, $this->outlet);

        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNull($order->completed_at);
        $this->assertEquals($this->business->id, $order->business_id);
        $this->assertEquals($this->outlet->id, $order->outlet_id);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_an_outlet_of_another_users_business_is_rejected(): void
    {
        $foreignOutlet = Outlet::factory()->create();

        $this->assertRejectedWith(
            OrderException::REASON_BUSINESS_MISMATCH,
            fn () => $this->service->createOrder($this->owner, $this->business, $foreignOutlet)
        );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_business_of_another_user_is_rejected(): void
    {
        $otherBusiness = Business::factory()->for($this->other)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->createOrder($this->owner, $otherBusiness, $otherOutlet)
        );

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->createOrder($this->other, $this->business, $this->outlet)
        );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_outlet_of_another_business_of_the_same_user_is_rejected(): void
    {
        $secondBusiness = Business::factory()->for($this->owner)->create();
        $secondOutlet = Outlet::factory()->for($secondBusiness)->create();

        $this->assertRejectedWith(
            OrderException::REASON_BUSINESS_MISMATCH,
            fn () => $this->service->createOrder($this->owner, $this->business, $secondOutlet)
        );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_missing_business_or_outlet_is_rejected(): void
    {
        $goneOutlet = Outlet::factory()->for($this->business)->create();
        $goneOutlet->delete();

        $this->assertRejectedWith(
            OrderException::REASON_NOT_FOUND,
            fn () => $this->service->createOrder($this->owner, $this->business, $goneOutlet)
        );

        $goneBusiness = Business::factory()->for($this->owner)->create();
        $outletOfGoneBusiness = Outlet::factory()->for($goneBusiness)->create();
        $goneBusiness->delete();

        $this->assertRejectedWith(
            OrderException::REASON_NOT_FOUND,
            fn () => $this->service->createOrder($this->owner, $goneBusiness, $outletOfGoneBusiness)
        );

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_service_ignores_forged_in_memory_attributes_when_creating(): void
    {
        $otherBusiness = Business::factory()->for($this->other)->create();

        $forgedOutlet = Outlet::query()->findOrFail($this->outlet->id);
        $forgedOutlet->business_id = $otherBusiness->id;

        $order = $this->service->createOrder($this->owner, $this->business, $forgedOutlet);

        $this->assertEquals($this->business->id, $order->business_id);
        $this->assertEquals($this->outlet->id, $order->outlet_id);

        $forgedBusiness = Business::query()->findOrFail($this->business->id);
        $forgedBusiness->user_id = $this->other->id;

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->createOrder($this->other, $forgedBusiness, $this->outlet)
        );

        $this->assertDatabaseCount('orders', 1);
    }

    // addItem

    public function test_the_first_add_creates_one_item_with_the_exact_snapshot(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business, [
            'name' => 'Large',
            'sku' => 'SNAP-0001',
            'price' => '12500.50',
        ]);
        $variant->product->forceFill(['name' => 'Iced Coffee'])->save();

        $item = $this->service->addItem($this->owner, $order, $variant, 3);

        $this->assertSame('Iced Coffee', $item->product_name);
        $this->assertSame('Large', $item->variant_name);
        $this->assertSame('SNAP-0001', $item->sku);
        $this->assertSame('12500.50', $item->unit_price);
        $this->assertSame(3, $item->quantity);
        $this->assertEquals($order->id, $item->order_id);
        $this->assertEquals($variant->id, $item->product_variant_id);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_a_variant_of_another_users_business_is_rejected(): void
    {
        $order = $this->order();
        $foreignVariant = $this->variantFor(Business::factory()->for($this->other)->create());

        $this->assertRejectedWith(
            OrderException::REASON_BUSINESS_MISMATCH,
            fn () => $this->service->addItem($this->owner, $order, $foreignVariant, 1)
        );

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_variant_of_another_business_of_the_same_user_is_rejected(): void
    {
        $order = $this->order();
        $secondBusiness = Business::factory()->for($this->owner)->create();
        $secondVariant = $this->variantFor($secondBusiness);

        $this->assertRejectedWith(
            OrderException::REASON_BUSINESS_MISMATCH,
            fn () => $this->service->addItem($this->owner, $order, $secondVariant, 1)
        );

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_another_user_cannot_add_to_the_order(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->addItem($this->other, $order, $variant, 1)
        );

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_a_missing_order_or_variant_is_rejected(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $goneVariant = $this->variantFor($this->business);
        $goneVariant->delete();

        $this->assertRejectedWith(
            OrderException::REASON_NOT_FOUND,
            fn () => $this->service->addItem($this->owner, $order, $goneVariant, 1)
        );

        $goneOrder = $this->order();
        $goneOrder->delete();

        $this->assertRejectedWith(
            OrderException::REASON_NOT_FOUND,
            fn () => $this->service->addItem($this->owner, $goneOrder, $variant, 1)
        );

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_invalid_quantities_are_rejected(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $invalid = [0, -1, '0', '-1', '1.5', 1.5, 1.0, 'abc', '', '1e3', '01', ' 1', '1 ', "1\n"];

        foreach ($invalid as $quantity) {
            $this->assertRejectedWith(
                OrderException::REASON_INVALID_QUANTITY,
                fn () => $this->service->addItem($this->owner, $order, $variant, $quantity)
            );
        }

        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_the_quantity_limits_are_enforced(): void
    {
        $order = $this->order();

        $this->assertSame(1, $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1)->quantity);
        $this->assertSame(1_000_000, $this->service->addItem($this->owner, $order, $this->variantFor($this->business), '1000000')->quantity);

        $over = $this->variantFor($this->business);

        foreach ([1_000_001, '1000001', '10000000', '999999999', '1234567890', '99999999999999999999', PHP_INT_MAX] as $quantity) {
            $this->assertRejectedWith(
                OrderException::REASON_QUANTITY_OVERFLOW,
                fn () => $this->service->addItem($this->owner, $order, $over, $quantity)
            );
        }

        $this->assertDatabaseCount('order_items', 2);
    }

    /**
     * Characterization, not a contract: without strict_types PHP coerces a bool to
     * int before the service runs, so the service never sees a bool.
     */
    public function test_php_coerces_a_bool_quantity_before_the_service_runs(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $this->assertSame(1, $this->service->addItem($this->owner, $order, $variant, true)->quantity);

        $this->assertRejectedWith(
            OrderException::REASON_INVALID_QUANTITY,
            fn () => $this->service->addItem($this->owner, $order, $variant, false)
        );

        $this->assertSame(1, OrderItem::query()->firstOrFail()->quantity);
    }

    public function test_the_same_variant_merges_into_one_row(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $first = $this->service->addItem($this->owner, $order, $variant, 2);
        $second = $this->service->addItem($this->owner, $order, $variant, '3');

        $this->assertEquals($first->id, $second->id);
        $this->assertSame(5, $second->quantity);
        $this->assertSame(5, $first->fresh()->quantity);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_the_combined_quantity_limit_is_enforced(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $item = $this->service->addItem($this->owner, $order, $variant, 999_999);

        $this->assertRejectedWith(
            OrderException::REASON_QUANTITY_OVERFLOW,
            fn () => $this->service->addItem($this->owner, $order, $variant, 2)
        );
        $this->assertSame(999_999, $item->fresh()->quantity);

        $merged = $this->service->addItem($this->owner, $order, $variant, 1);
        $this->assertSame(1_000_000, $merged->quantity);

        $this->assertRejectedWith(
            OrderException::REASON_QUANTITY_OVERFLOW,
            fn () => $this->service->addItem($this->owner, $order, $variant, 1)
        );
        $this->assertSame(1_000_000, $item->fresh()->quantity);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_the_first_snapshot_is_kept_when_the_variant_changes_between_adds(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business, [
            'name' => 'Small',
            'sku' => 'KEEP-0001',
            'price' => '10000.00',
        ]);
        $variant->product->forceFill(['name' => 'Original product'])->save();

        $item = $this->service->addItem($this->owner, $order, $variant, 1);

        $variant->product->forceFill(['name' => 'Renamed product'])->save();
        $variant->forceFill(['name' => 'Renamed variant', 'sku' => 'KEEP-0002', 'price' => '99999.99'])->save();

        $merged = $this->service->addItem($this->owner, $order, $variant, 2);

        $this->assertEquals($item->id, $merged->id);
        $this->assertSame(3, $merged->quantity);
        $this->assertSame('Original product', $merged->product_name);
        $this->assertSame('Small', $merged->variant_name);
        $this->assertSame('KEEP-0001', $merged->sku);
        $this->assertSame('10000.00', $merged->unit_price);

        $newItem = $this->service->addItem($this->owner, $this->order(), $variant, 1);

        $this->assertSame('Renamed product', $newItem->product_name);
        $this->assertSame('Renamed variant', $newItem->variant_name);
        $this->assertSame('KEEP-0002', $newItem->sku);
        $this->assertSame('99999.99', $newItem->unit_price);
    }

    public function test_the_service_ignores_forged_in_memory_attributes_when_adding(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business, [
            'name' => 'Real',
            'sku' => 'REAL-0001',
            'price' => '5000.00',
        ]);

        $forgedVariant = ProductVariant::query()->findOrFail($variant->id);
        $forgedVariant->forceFill(['name' => 'Forged', 'sku' => 'FORGED-1', 'price' => '1.00']);

        $otherBusiness = Business::factory()->for($this->other)->create();
        $forgedOrder = Order::query()->findOrFail($order->id);
        $forgedOrder->business_id = $otherBusiness->id;

        $item = $this->service->addItem($this->owner, $forgedOrder, $forgedVariant, 1);

        $this->assertEquals($order->id, $item->order_id);
        $this->assertSame('Real', $item->variant_name);
        $this->assertSame('REAL-0001', $item->sku);
        $this->assertSame('5000.00', $item->unit_price);
    }

    public function test_a_completed_order_rejects_adding(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $this->service->addItem($this->owner, $order, $variant, 1);
        $this->service->completeOrder($this->owner, $order);

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1)
        );

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->addItem($this->owner, $order, $variant, 1)
        );

        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(1, OrderItem::query()->firstOrFail()->quantity);
    }

    public function test_a_stale_in_memory_order_is_judged_by_its_database_state(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        $this->service->addItem($this->owner, $order, $variant, 1);
        $this->service->completeOrder($this->owner, Order::query()->findOrFail($order->id));

        $this->assertSame(OrderStatus::Open, $order->status);

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1)
        );
    }

    // completeOrder

    public function test_an_order_with_items_is_completed(): void
    {
        $order = $this->order();
        $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1);

        $completed = $this->service->completeOrder($this->owner, $order);

        $this->assertSame(OrderStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);
    }

    public function test_an_empty_order_cannot_be_completed(): void
    {
        $order = $this->order();

        $this->assertRejectedWith(
            OrderException::REASON_EMPTY_ORDER,
            fn () => $this->service->completeOrder($this->owner, $order)
        );

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Open, $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_completing_twice_is_rejected_and_keeps_completed_at(): void
    {
        $order = $this->order();
        $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1);

        $completed = $this->service->completeOrder($this->owner, $order);
        $before = Order::query()->findOrFail($order->id)->completed_at->toDateTimeString();

        // Move the clock so that an overwritten completed_at would show up.
        $this->travel(5)->minutes();

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeOrder($this->owner, $order)
        );

        $this->assertRejectedWith(
            OrderException::REASON_ORDER_NOT_OPEN,
            fn () => $this->service->completeOrder($this->owner, $completed)
        );

        $after = Order::query()->findOrFail($order->id)->completed_at->toDateTimeString();
        $this->assertSame($before, $after);
    }

    public function test_another_user_cannot_complete_the_order(): void
    {
        $order = $this->order();
        $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1);

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->completeOrder($this->other, $order)
        );

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Open, $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_ownership_is_checked_before_the_order_state(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);
        $this->service->addItem($this->owner, $order, $variant, 1);
        $this->service->completeOrder($this->owner, $order);

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->addItem($this->other, $order, $variant, 1)
        );

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->completeOrder($this->other, $order)
        );

        $emptyOrder = $this->order();

        $this->assertRejectedWith(
            OrderException::REASON_UNAUTHORIZED,
            fn () => $this->service->completeOrder($this->other, $emptyOrder)
        );
    }

    public function test_a_missing_order_is_rejected_on_completion(): void
    {
        $order = $this->order();
        $order->delete();

        $this->assertRejectedWith(
            OrderException::REASON_NOT_FOUND,
            fn () => $this->service->completeOrder($this->owner, $order)
        );
    }

    public function test_completion_touches_no_stock(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        (new StockService)->recordOpening($this->owner, $this->outlet, $variant, '10');

        $this->service->addItem($this->owner, $order, $variant, 2);
        $this->service->completeOrder($this->owner, $order);

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_levels', 1);
        $this->assertDatabaseHas('stock_levels', [
            'outlet_id' => $this->outlet->id,
            'product_variant_id' => $variant->id,
            'quantity' => '10.000',
        ]);
    }

    // Rollback

    public function test_a_failure_after_the_order_insert_leaves_no_order(): void
    {
        Order::created(function () {
            throw new LogicException('simulated failure');
        });

        try {
            $this->service->createOrder($this->owner, $this->business, $this->outlet);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_failure_after_the_item_insert_leaves_no_item(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);

        OrderItem::created(function () {
            throw new LogicException('simulated failure');
        });

        try {
            $this->service->addItem($this->owner, $order, $variant, 2);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    public function test_a_failure_after_the_merge_restores_the_previous_quantity(): void
    {
        $order = $this->order();
        $variant = $this->variantFor($this->business);
        $item = $this->service->addItem($this->owner, $order, $variant, 4);

        OrderItem::updated(function () {
            throw new LogicException('simulated failure');
        });

        try {
            $this->service->addItem($this->owner, $order, $variant, 3);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame(4, $item->fresh()->quantity);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_a_failure_after_the_completion_leaves_the_order_open(): void
    {
        $order = $this->order();
        $this->service->addItem($this->owner, $order, $this->variantFor($this->business), 1);

        Order::updated(function () {
            throw new LogicException('simulated failure');
        });

        try {
            $this->service->completeOrder($this->owner, $order);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (LogicException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Open, $fresh->status);
        $this->assertNull($fresh->completed_at);
    }
}
