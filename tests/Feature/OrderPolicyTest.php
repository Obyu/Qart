<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    private function ownedOrder(): Order
    {
        $business = Business::factory()->for($this->owner)->create();

        return Order::factory()->for($business)->create();
    }

    public function test_the_policy_is_registered_for_orders(): void
    {
        $this->assertInstanceOf(OrderPolicy::class, Gate::getPolicyFor(Order::class));
    }

    public function test_the_owner_may_view_update_and_complete_their_order(): void
    {
        $order = $this->ownedOrder();

        foreach (['view', 'update', 'complete'] as $ability) {
            $this->assertTrue(
                Gate::forUser($this->owner)->allows($ability, $order),
                "The owner should be allowed to {$ability} the order."
            );
        }
    }

    public function test_another_user_may_not_view_update_or_complete_the_order(): void
    {
        $order = $this->ownedOrder();

        foreach (['view', 'update', 'complete'] as $ability) {
            $this->assertFalse(
                Gate::forUser($this->other)->allows($ability, $order),
                "Another user must not be allowed to {$ability} the order."
            );
        }
    }

    public function test_any_user_may_view_any_and_create_orders(): void
    {
        foreach ([$this->owner, $this->other] as $user) {
            $this->assertTrue(Gate::forUser($user)->allows('viewAny', Order::class));
            $this->assertTrue(Gate::forUser($user)->allows('create', Order::class));
        }
    }

    public function test_nobody_may_delete_orders_not_even_the_owner(): void
    {
        $order = $this->ownedOrder();

        foreach ([$this->owner, $this->other] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows('delete', $order));
            $this->assertFalse(Gate::forUser($user)->allows('deleteAny', Order::class));
        }
    }
}
