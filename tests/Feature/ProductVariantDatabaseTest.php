<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantDatabaseTest extends TestCase
{
    use RefreshDatabase;

    // Relationships

    public function test_product_has_many_variants(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->count(3)->for($product)->create();
        ProductVariant::factory()->create(); // belongs to a different product

        $this->assertCount(3, $product->variants);
        $this->assertTrue($product->variants->every(fn ($variant) => $variant->product_id == $product->id));
    }

    public function test_variant_belongs_to_a_product(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->assertTrue($variant->product->is($product));
    }

    // Creation

    public function test_variant_can_be_created_through_its_product(): void
    {
        $product = Product::factory()->create();

        $variant = $product->variants()->create(['name' => 'Small', 'sku' => 'KS-S']);

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'product_id' => $product->id,
            'name' => 'Small',
            'sku' => 'KS-S',
        ]);
    }

    public function test_product_id_is_not_mass_assignable(): void
    {
        $variant = (new ProductVariant)->fill(['name' => 'A', 'sku' => 'A-1', 'product_id' => 5]);

        $this->assertNull($variant->product_id);
        $this->assertSame('A-1', $variant->sku);
    }

    public function test_price_is_mass_assignable(): void
    {
        $variant = (new ProductVariant)->fill(['name' => 'A', 'sku' => 'A-1', 'price' => '9.99']);

        $this->assertSame('9.99', $variant->price);
    }

    // SKU rules enforced by the database

    public function test_sku_is_required(): void
    {
        $product = Product::factory()->create();

        $this->expectException(QueryException::class);

        $product->variants()->create(['name' => 'No SKU', 'sku' => null, 'price' => '10.00']);
    }

    public function test_sku_of_exactly_100_characters_is_accepted(): void
    {
        $variant = ProductVariant::factory()->create(['sku' => str_repeat('A', 100)]);

        $this->assertSame(100, strlen($variant->fresh()->sku));
    }

    public function test_sku_longer_than_100_characters_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        ProductVariant::factory()->create(['sku' => str_repeat('A', 101)]);
    }

    public function test_sku_must_be_globally_unique_across_businesses(): void
    {
        ProductVariant::factory()->create(['sku' => 'AM-001']);

        try {
            // The factory gives this variant its own product, category and business.
            ProductVariant::factory()->create(['sku' => 'AM-001']);
            $this->fail('Expected the duplicate SKU to be rejected.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertDatabaseCount('product_variants', 1);
            // Two businesses exist, so the rejected variant was in a different one.
            $this->assertSame(2, Business::count());
        }
    }

    public function test_sku_uniqueness_is_case_insensitive(): void
    {
        ProductVariant::factory()->create(['sku' => 'KS-S']);

        $this->expectException(UniqueConstraintViolationException::class);

        ProductVariant::factory()->create(['sku' => 'ks-s']);
    }

    // Price rules enforced by the database

    public function test_price_is_stored_with_two_decimals(): void
    {
        $variant = ProductVariant::factory()->create(['price' => '12.5']);

        $this->assertSame('12.50', $variant->fresh()->price);
    }

    public function test_price_may_be_null_while_the_column_is_still_nullable(): void
    {
        $variant = ProductVariant::factory()->create(['price' => null]);

        $this->assertNull($variant->fresh()->price);
    }

    public function test_price_of_zero_is_accepted(): void
    {
        $variant = ProductVariant::factory()->create(['price' => '0.00']);

        $this->assertSame('0.00', $variant->fresh()->price);
    }

    public function test_the_largest_price_the_column_can_hold_is_accepted(): void
    {
        $variant = ProductVariant::factory()->create(['price' => '999999999999.99']);

        $this->assertSame('999999999999.99', $variant->fresh()->price);
    }

    public function test_a_price_above_the_column_maximum_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        ProductVariant::factory()->create(['price' => '1000000000000.00']);
    }

    public function test_a_negative_price_is_rejected_on_insert(): void
    {
        $this->assertNegativePriceRejected(fn () => ProductVariant::factory()->create(['price' => '-0.01']));

        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_a_negative_price_is_rejected_on_update(): void
    {
        $variant = ProductVariant::factory()->create(['price' => '5.00']);

        $this->assertNegativePriceRejected(fn () => $variant->update(['price' => '-1.00']));

        $this->assertSame('5.00', $variant->fresh()->price);
    }

    private function assertNegativePriceRejected(callable $write): void
    {
        try {
            $write();

            $this->fail('Expected the database to reject the negative price.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('product_variants_price_non_negative', $e->getMessage());
        }
    }

    // Delete behavior

    public function test_deleting_a_product_deletes_its_variants(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->count(2)->for($product)->create();
        $other = ProductVariant::factory()->create();

        $product->delete();

        $this->assertDatabaseMissing('product_variants', ['product_id' => $product->id]);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_variants', ['id' => $other->id]);
    }

    public function test_deleting_a_variant_keeps_its_product(): void
    {
        $product = Product::factory()->create();
        $variants = ProductVariant::factory()->count(2)->for($product)->create();

        $variants->first()->delete();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertCount(1, $product->fresh()->variants);
    }

    public function test_deleting_a_business_also_removes_its_products_variants(): void
    {
        $business = Business::factory()->create();
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();
        ProductVariant::factory()->count(2)->for($product)->create();
        $other = ProductVariant::factory()->create();

        $business->delete();

        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_variants', ['id' => $other->id]);
    }

    // Factory

    public function test_factory_creates_valid_variants_with_distinct_skus(): void
    {
        $variants = ProductVariant::factory()->count(20)->create();

        $this->assertCount(20, $variants->pluck('sku')->unique());

        $variants->each(function (ProductVariant $variant) {
            $this->assertNotEmpty($variant->name);
            $this->assertMatchesRegularExpression('/^\d{1,12}\.\d{2}$/', $variant->price);
            $this->assertLessThanOrEqual(100, strlen($variant->sku));
            $this->assertNotNull($variant->product);
            $this->assertNotNull($variant->product->business_id);
        });
    }
}
