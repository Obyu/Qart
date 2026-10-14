<?php

namespace Tests\Feature;

use App\Exceptions\BusinessHasStockHistoryException;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessStockHistoryDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A business with one outlet, category, product and variant.
     *
     * @return array{business: Business, outlet: Outlet, variant: ProductVariant}
     */
    private function stockableBusiness(): array
    {
        $business = Business::factory()->create();
        $outlet = Outlet::factory()->for($business)->create();
        $category = Category::factory()->for($business)->create();
        $product = Product::factory()->for($category)->create();
        $variant = ProductVariant::factory()->for($product)->create();

        return compact('business', 'outlet', 'variant');
    }

    private function recordHistory(Outlet $outlet, ProductVariant $variant): StockMovement
    {
        return StockMovement::factory()->create([
            'outlet_id' => $outlet->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    public function test_a_business_with_stock_history_cannot_be_deleted(): void
    {
        ['business' => $business, 'outlet' => $outlet, 'variant' => $variant] = $this->stockableBusiness();
        $this->recordHistory($outlet, $variant);

        try {
            $business->delete();
            $this->fail('Expected the deletion to be blocked.');
        } catch (BusinessHasStockHistoryException $e) {
            $this->assertInstanceOf(StockException::class, $e);
            $this->assertSame(BusinessHasStockHistoryException::REASON_BUSINESS_HAS_STOCK_HISTORY, $e->reason);
        }

        $this->assertTrue($business->exists);
        $this->assertDatabaseHas('businesses', ['id' => $business->id]);
    }

    public function test_a_blocked_deletion_leaves_the_whole_business_and_its_ledger_untouched(): void
    {
        ['business' => $business, 'outlet' => $outlet, 'variant' => $variant] = $this->stockableBusiness();
        $movement = $this->recordHistory($outlet, $variant);

        try {
            $business->delete();
        } catch (BusinessHasStockHistoryException) {
            // expected
        }

        $this->assertDatabaseCount('outlets', 1);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_a_business_without_stock_history_is_still_deleted(): void
    {
        ['business' => $business] = $this->stockableBusiness();

        $this->assertTrue($business->delete());

        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
        $this->assertDatabaseCount('outlets', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_stock_history_of_another_business_does_not_block_the_deletion(): void
    {
        ['outlet' => $otherOutlet, 'variant' => $otherVariant] = $this->stockableBusiness();
        $this->recordHistory($otherOutlet, $otherVariant);
        ['business' => $business] = $this->stockableBusiness();

        $this->assertTrue($business->delete());

        $this->assertDatabaseMissing('businesses', ['id' => $business->id]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('outlets', ['id' => $otherOutlet->id]);
    }
}
