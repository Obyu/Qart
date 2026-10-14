<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLevel>
 */
class StockLevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The variant is created inside the outlet's business, so a default row never
     * mixes businesses. Factory rows bypass StockService: no movement is written.
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
            'quantity' => '0.000',
        ];
    }
}
