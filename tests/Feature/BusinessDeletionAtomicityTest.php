<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BusinessDeletionAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private function businessWithThreeProducts(): Business
    {
        $business = Business::factory()->create();
        $category = Category::factory()->for($business)->create();
        Product::factory()->count(3)->for($category)->create();

        return $business;
    }

    public function test_products_are_deleted_through_eloquent_and_the_business_is_removed(): void
    {
        $business = $this->businessWithThreeProducts();
        $deletedProducts = 0;
        Product::deleted(function () use (&$deletedProducts) {
            $deletedProducts++;
        });

        $business->delete();

        $this->assertSame(3, $deletedProducts);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
    }

    public function test_a_failing_business_delete_leaves_its_products_in_place(): void
    {
        $business = $this->businessWithThreeProducts();
        Business::deleting(function () {
            throw new RuntimeException('simulated failure');
        });

        try {
            $business->delete();
            $this->fail('Expected the simulated failure to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertDatabaseHas('businesses', ['id' => $business->id]);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 3);
    }

    public function test_a_cancelled_business_delete_leaves_its_products_in_place(): void
    {
        $business = $this->businessWithThreeProducts();
        Business::deleting(fn () => false);

        $this->assertFalse($business->delete());

        $this->assertDatabaseHas('businesses', ['id' => $business->id]);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 3);
    }
}
