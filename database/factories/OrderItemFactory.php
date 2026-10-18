<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The variant is created inside the order's business, and the snapshot is
     * taken from that variant, as OrderService will do.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_variant_id' => fn (array $attributes) => ProductVariant::factory()->for(
                Product::factory()->for(
                    Category::factory()->for(Order::query()->findOrFail($attributes['order_id'])->business)
                )
            ),
            'product_name' => fn (array $attributes) => $this->variantOf($attributes)->product->name,
            'variant_name' => fn (array $attributes) => $this->variantOf($attributes)->name,
            'sku' => fn (array $attributes) => $this->variantOf($attributes)->sku,
            'unit_price' => fn (array $attributes) => $this->variantOf($attributes)->price,
            'quantity' => fake()->numberBetween(1, 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function variantOf(array $attributes): ProductVariant
    {
        return ProductVariant::query()->with('product')->findOrFail($attributes['product_variant_id']);
    }
}
