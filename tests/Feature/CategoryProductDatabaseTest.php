<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryProductDatabaseTest extends TestCase
{
    use RefreshDatabase;

    // Relationships

    public function test_category_belongs_to_a_business_and_business_has_many_categories(): void
    {
        $business = Business::factory()->create();
        $category = Category::factory()->for($business)->create();
        Category::factory()->for($business)->create();

        $this->assertTrue($category->business->is($business));
        $this->assertCount(2, $business->categories);
    }

    public function test_product_belongs_to_its_category_and_the_categorys_business(): void
    {
        $business = Business::factory()->create();
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();

        $this->assertTrue($product->category->is($category));
        $this->assertTrue($product->business->is($business));
        $this->assertCount(1, $category->products);
        $this->assertCount(1, $business->products);
    }

    // Product business is derived from its category

    public function test_product_business_comes_from_its_category_even_if_another_is_supplied(): void
    {
        $category = Category::factory()->create();
        $otherBusiness = Business::factory()->create();

        $product = Product::factory()->for($category)->create(['business_id' => $otherBusiness->id]);

        $this->assertSame($category->business_id, $product->fresh()->business_id);
    }

    public function test_product_business_follows_the_category_when_the_category_changes(): void
    {
        $product = Product::factory()->create();
        $otherCategory = Category::factory()->create();

        $product->update(['category_id' => $otherCategory->id]);

        $this->assertSame($otherCategory->business_id, $product->fresh()->business_id);
    }

    public function test_business_id_is_not_mass_assignable(): void
    {
        $category = (new Category)->fill(['name' => 'A', 'business_id' => 123]);
        $product = (new Product)->fill(['name' => 'A', 'category_id' => 7, 'business_id' => 123]);

        $this->assertNull($category->business_id);
        $this->assertNull($product->business_id);
        $this->assertSame(7, $product->category_id);
    }

    // Foreign keys

    public function test_foreign_keys_exist_with_the_expected_delete_rules(): void
    {
        $foreignKeys = collect(DB::select(
            'select TABLE_NAME as t, CONSTRAINT_NAME as c, REFERENCED_TABLE_NAME as r, DELETE_RULE as d '
            .'from information_schema.REFERENTIAL_CONSTRAINTS where CONSTRAINT_SCHEMA = database()'
        ))->mapWithKeys(fn ($fk) => [$fk->t.'.'.$fk->c => $fk->r.':'.$fk->d]);

        $this->assertSame('businesses:CASCADE', $foreignKeys->get('categories.categories_business_id_foreign'));
        $this->assertSame('businesses:CASCADE', $foreignKeys->get('products.products_business_id_foreign'));
        $this->assertSame('categories:RESTRICT', $foreignKeys->get('products.products_category_id_foreign'));
    }

    // Delete behavior

    public function test_deleting_a_business_deletes_its_categories(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        Category::factory()->count(2)->for($business)->create();
        Category::factory()->for($other)->create();

        $business->delete();

        $this->assertDatabaseMissing('categories', ['business_id' => $business->id]);
        $this->assertDatabaseCount('categories', 1);
    }

    public function test_deleting_a_business_deletes_its_products(): void
    {
        $business = Business::factory()->create();
        $other = Business::factory()->create();
        $category = Category::factory()->for($business)->create();
        Product::factory()->count(2)->for($category)->create();
        $otherCategory = Category::factory()->for($other)->create();
        Product::factory()->for($otherCategory)->create();

        $business->delete();

        $this->assertDatabaseMissing('categories', ['business_id' => $business->id]);
        $this->assertDatabaseMissing('products', ['business_id' => $business->id]);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_deleting_a_category_that_has_products_is_restricted(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();

        try {
            $category->delete();
            $this->fail('Expected the delete to be restricted by the foreign key.');
        } catch (QueryException $e) {
            $this->assertDatabaseHas('categories', ['id' => $category->id]);
            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }
    }

    public function test_deleting_a_category_without_products_works(): void
    {
        $category = Category::factory()->create();

        $category->delete();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}
