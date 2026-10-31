<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BusinessAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_counts_only_completed_orders_using_item_price_snapshots_and_owned_businesses(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();
        $variant = ProductVariant::factory()->for($product)->create([
            'price' => '999.00',
        ]);

        $completedOrder = Order::factory()
            ->for($business)
            ->for($outlet)
            ->completed()
            ->create();

        OrderItem::factory()
            ->for($completedOrder)
            ->for($variant)
            ->create([
                'unit_price' => '12.50',
                'quantity' => 2,
            ]);

        $openOrder = Order::factory()
            ->for($business)
            ->for($outlet)
            ->create();

        OrderItem::factory()
            ->for($openOrder)
            ->for($variant)
            ->create([
                'unit_price' => '50.00',
                'quantity' => 3,
            ]);

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();
        $otherCategory = Category::factory()->for($otherBusiness)->create();
        $otherProduct = Product::factory()->for($otherCategory)->create();
        $otherVariant = ProductVariant::factory()->for($otherProduct)->create();

        $otherOrder = Order::factory()
            ->for($otherBusiness)
            ->for($otherOutlet)
            ->completed()
            ->create();

        OrderItem::factory()
            ->for($otherOrder)
            ->for($otherVariant)
            ->create([
                'unit_price' => '500.00',
                'quantity' => 4,
            ]);

        $analytics = new BusinessAnalyticsService;

        $result = $analytics->salesSummary($owner);

        $this->assertSame('25.00', $result['total_revenue']);
        $this->assertSame(1, $result['completed_orders']);
        $this->assertSame(1, $result['open_orders']);
    }

    public function test_payment_metrics_count_only_confirmed_payments_for_owned_businesses(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();

        $completedOrder = Order::factory()
            ->for($business)
            ->for($outlet)
            ->completed()
            ->create();

        $openOrder = Order::factory()
            ->for($business)
            ->for($outlet)
            ->create();

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $otherOrder = Order::factory()
            ->for($otherBusiness)
            ->for($otherOutlet)
            ->completed()
            ->create();

        $now = now()->toDateTimeString();

        // Cash: revenue is the bill amount, not the cash received.
        DB::table('payments')->insert([
            'order_id' => $completedOrder->id,
            'method' => 'cash',
            'status' => 'confirmed',
            'amount' => '20.00',
            'amount_received' => '25.00',
            'change_amount' => '5.00',
            'confirmed_by' => $owner->id,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Pending QRIS should be counted as pending, not confirmed.
        DB::table('payments')->insert([
            'order_id' => $openOrder->id,
            'method' => 'qris',
            'status' => 'pending',
            'amount' => '30.00',
            'qris_reference' => 'QR-ANALYTICS-PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Rejected QRIS remains in history but is not confirmed revenue.
        DB::table('payments')->insert([
            'order_id' => $openOrder->id,
            'method' => 'qris',
            'status' => 'rejected',
            'amount' => '40.00',
            'qris_reference' => 'QR-ANALYTICS-REJECTED',
            'rejected_by' => $owner->id,
            'rejected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Another owner's confirmed payment must not leak into this report.
        DB::table('payments')->insert([
            'order_id' => $otherOrder->id,
            'method' => 'qris',
            'status' => 'confirmed',
            'amount' => '500.00',
            'qris_reference' => 'QR-OTHER-OWNER',
            'confirmed_by' => $otherOwner->id,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = (new BusinessAnalyticsService)->salesSummary($owner);

        $this->assertSame('20.00', $result['confirmed_payments']);
        $this->assertSame(1, $result['pending_qris_payments']);
    }
}
