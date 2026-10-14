<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The variant is created inside the outlet's business. Factory rows bypass
     * StockService: they do not touch stock_levels, so use them for integrity
     * tests only.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'product_variant_id' => fn (array $attributes) => ProductVariant::factory()->for(
                Product::factory()->for(
                    Category::factory()->for(Outlet::query()->findOrFail($attributes['outlet_id'])->business)
                )
            ),
            'type' => StockMovementType::Adjustment,
            'quantity_delta' => '1.000',
            'quantity_after' => '1.000',
            'user_id' => null,
        ];
    }
}
