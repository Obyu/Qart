<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The outlet is created inside the order's business, so the pair is valid.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'outlet_id' => fn (array $attributes) => Outlet::factory()->for(
                Business::query()->findOrFail($attributes['business_id'])
            ),
            'status' => OrderStatus::Open,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
