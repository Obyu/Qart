<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Exceptions\OrderException;
use App\Models\Business;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_create_order_at_the_assigned_outlet(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $assignedOutlet = Outlet::factory()->for($business)->create();

        $cashier = User::factory()->create();
        $cashier->forceFill([
            'role' => StaffRole::Cashier->value,
            'business_id' => $business->id,
            'outlet_id' => $assignedOutlet->id,
        ])->save();

        $order = app(OrderService::class)->createOrder(
            $cashier,
            $business,
            $assignedOutlet,
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals($business->id, $order->business_id);
        $this->assertEquals($assignedOutlet->id, $order->outlet_id);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cashier_cannot_create_order_at_another_outlet(): void
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();

        $assignedOutlet = Outlet::factory()->for($business)->create();
        $otherOutlet = Outlet::factory()->for($business)->create();

        $cashier = User::factory()->create();
        $cashier->forceFill([
            'role' => StaffRole::Cashier->value,
            'business_id' => $business->id,
            'outlet_id' => $assignedOutlet->id,
        ])->save();

        try {
            app(OrderService::class)->createOrder(
                $cashier,
                $business,
                $otherOutlet,
            );

            $this->fail('Expected an unauthorized OrderException.');
        } catch (OrderException $exception) {
            $this->assertSame(
                OrderException::REASON_UNAUTHORIZED,
                $exception->reason,
            );
        }

        $this->assertDatabaseCount('orders', 0);
    }
}
