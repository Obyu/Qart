<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessOutletRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->for($user)->create();

        $this->assertTrue($business->user->is($user));
    }

    public function test_user_can_have_multiple_businesses(): void
    {
        $user = User::factory()->create();
        Business::factory()->count(2)->for($user)->create();

        $this->assertCount(2, $user->businesses);
    }

    public function test_outlet_belongs_to_a_business(): void
    {
        $business = Business::factory()->create();
        $outlet = Outlet::factory()->for($business)->create();

        $this->assertTrue($outlet->business->is($business));
    }

    public function test_business_can_have_multiple_outlets(): void
    {
        $business = Business::factory()->create();
        Outlet::factory()->count(3)->for($business)->create();

        $this->assertCount(3, $business->outlets);
    }

    public function test_outlet_cannot_reference_a_nonexistent_business(): void
    {
        $this->expectException(QueryException::class);

        Outlet::factory()->create(['business_id' => 999999]);
    }

    public function test_user_who_owns_a_business_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        Business::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_deleting_a_business_deletes_its_outlets(): void
    {
        $business = Business::factory()->create();
        Outlet::factory()->count(2)->for($business)->create();

        $business->delete();

        $this->assertDatabaseCount('outlets', 0);
    }
}
