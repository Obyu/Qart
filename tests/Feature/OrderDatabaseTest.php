<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A valid raw row for the orders table: the outlet belongs to the business.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderRow(array $overrides = []): array
    {
        $outlet = Outlet::factory()->create();

        return $overrides + [
            'business_id' => $outlet->business_id,
            'outlet_id' => $outlet->id,
            'status' => 'open',
            'completed_at' => null,
        ];
    }

    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail($message);
    }

    public function test_an_order_defaults_to_open_without_completed_at(): void
    {
        $row = $this->orderRow();
        unset($row['status'], $row['completed_at']);

        $order = Order::query()->findOrFail(DB::table('orders')->insertGetId($row));

        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNull($order->completed_at);
    }

    public function test_the_factory_builds_open_and_completed_orders(): void
    {
        $open = Order::factory()->create()->fresh();
        $completed = Order::factory()->completed()->create()->fresh();

        $this->assertSame(OrderStatus::Open, $open->status);
        $this->assertNull($open->completed_at);
        $this->assertSame(OrderStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_an_outlet_of_another_business_is_rejected(): void
    {
        $business = Business::factory()->create();
        $foreignOutlet = Outlet::factory()->create();

        $this->assertRejected(
            fn () => Order::factory()->create([
                'business_id' => $business->id,
                'outlet_id' => $foreignOutlet->id,
            ]),
            'An order must not reference an outlet of another business.'
        );
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->assertRejected(
            fn () => DB::table('orders')->insert($this->orderRow(['status' => 'cancelled'])),
            'Only open and completed are valid statuses.'
        );
    }

    public function test_status_and_completed_at_must_agree(): void
    {
        $this->assertRejected(
            fn () => DB::table('orders')->insert($this->orderRow(['status' => 'completed'])),
            'A completed order without completed_at should be rejected.'
        );

        $this->assertRejected(
            fn () => DB::table('orders')->insert($this->orderRow(['completed_at' => now()->toDateTimeString()])),
            'An open order with completed_at should be rejected.'
        );

        $order = Order::factory()->create();

        $this->assertRejected(
            fn () => DB::table('orders')->where('id', $order->id)->update(['status' => 'completed']),
            'Completing an order without completed_at should be rejected.'
        );

        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'completed',
            'completed_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_quantity_must_be_between_one_and_one_million(): void
    {
        $order = Order::factory()->create();

        foreach ([1, 1_000_000] as $quantity) {
            $item = OrderItem::factory()->for($order)->create(['quantity' => $quantity]);

            $this->assertSame($quantity, $item->fresh()->quantity);
        }

        foreach ([0, -1, 1_000_001] as $quantity) {
            $this->assertRejected(
                fn () => OrderItem::factory()->for($order)->create(['quantity' => $quantity]),
                "Quantity {$quantity} should be rejected."
            );
        }
    }

    public function test_unit_price_cannot_be_negative(): void
    {
        $order = Order::factory()->create();

        $free = OrderItem::factory()->for($order)->create(['unit_price' => '0.00']);
        $this->assertSame('0.00', $free->fresh()->unit_price);

        $this->assertRejected(
            fn () => OrderItem::factory()->for($order)->create(['unit_price' => '-0.01']),
            'A negative unit price should be rejected.'
        );
    }

    public function test_a_variant_appears_once_per_order(): void
    {
        $item = OrderItem::factory()->create();

        $this->assertRejected(
            fn () => OrderItem::factory()->for($item->order)->create([
                'product_variant_id' => $item->product_variant_id,
            ]),
            'The same variant must not appear twice in one order.'
        );

        $otherOrder = Order::factory()->for($item->order->business)->create();
        OrderItem::factory()->for($otherOrder)->create(['product_variant_id' => $item->product_variant_id]);

        $this->assertDatabaseCount('order_items', 2);
    }

    public function test_the_snapshot_survives_catalog_changes(): void
    {
        $item = OrderItem::factory()->create()->fresh();
        $fields = ['product_name', 'variant_name', 'sku', 'unit_price'];
        $snapshot = $item->only($fields);

        $variant = $item->productVariant;
        $variant->product->forceFill(['name' => 'Renamed product'])->save();
        $variant->forceFill(['name' => 'Renamed variant', 'sku' => 'RENAMED-0001', 'price' => '9999.99'])->save();

        $this->assertSame('Renamed variant', $variant->fresh()->name);
        $this->assertSame($snapshot, $item->fresh()->only($fields));
    }

    public function test_deleting_an_order_deletes_its_items_but_not_the_variant(): void
    {
        $item = OrderItem::factory()->create();

        $item->order->delete();

        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $item->product_variant_id]);
    }

    public function test_a_variant_with_order_items_cannot_be_deleted(): void
    {
        $item = OrderItem::factory()->create();

        $this->assertRejected(
            fn () => $item->productVariant->delete(),
            'A variant that was ordered must not be deleted.'
        );

        $this->assertDatabaseHas('product_variants', ['id' => $item->product_variant_id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
    }

    public function test_an_outlet_with_orders_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();

        $this->assertRejected(
            fn () => $order->outlet->delete(),
            'An outlet with orders must not be deleted.'
        );

        $this->assertDatabaseHas('outlets', ['id' => $order->outlet_id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_business_with_an_order_cannot_be_deleted(): void
    {
        $order = Order::factory()->create();

        $this->assertRejected(
            fn () => $order->business->delete(),
            'A business with orders must not be deleted.'
        );

        $this->assertDatabaseHas('businesses', ['id' => $order->business_id]);
        $this->assertDatabaseHas('outlets', ['id' => $order->outlet_id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_blocked_business_deletion_rolls_back_earlier_product_deletions(): void
    {
        $order = Order::factory()->create();
        $category = Category::factory()->for($order->business)->create();
        // Lower id than the ordered product, so it is deleted first and must be restored.
        $freeProduct = Product::factory()->for($category)->create();
        $item = OrderItem::factory()->for($order)->create();

        $this->assertRejected(
            fn () => $order->business->delete(),
            'A business with ordered products must not be deleted.'
        );

        $this->assertDatabaseHas('products', ['id' => $freeProduct->id]);
        $this->assertDatabaseHas('products', ['id' => $item->productVariant->product_id]);
        $this->assertDatabaseHas('businesses', ['id' => $order->business_id]);
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
    }

    public function test_relations_connect_orders_items_and_catalog(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;

        $this->assertTrue($order->items->first()->is($item));
        $this->assertTrue($order->business->orders->first()->is($order));
        $this->assertTrue($order->outlet->orders->first()->is($order));
        $this->assertTrue($item->productVariant->orderItems->first()->is($item));
    }

    public function test_the_factories_build_a_consistent_business_outlet_variant_chain(): void
    {
        $item = OrderItem::factory()->create();
        $order = $item->order;
        $variant = $item->productVariant;

        $this->assertEquals($order->business_id, $order->outlet->business_id);
        $this->assertEquals($order->business_id, $variant->product->business_id);
        $this->assertSame($variant->product->name, $item->product_name);
        $this->assertSame($variant->name, $item->variant_name);
        $this->assertSame($variant->sku, $item->sku);
        $this->assertEquals($variant->price, $item->unit_price);
    }
}
