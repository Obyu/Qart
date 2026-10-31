<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_builds_consistent_data_through_the_services(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(6, Order::query()->where('status', 'completed')->count());
        $this->assertSame(1, Order::query()->where('status', 'cancelled')->count());
        $this->assertSame(1, Order::query()->where('status', 'open')->count());

        $this->assertSame(7, Payment::query()->where('status', 'confirmed')->count());
        $this->assertSame(1, Payment::query()->where('status', 'pending')->count());

        $revenue = Money::sum(Payment::query()->where('status', 'confirmed')->pluck('amount'));
        $this->assertSame(0, Money::compare($revenue, '264000.00'));
    }

    public function test_the_demo_seeder_refuses_to_run_when_users_exist(): void
    {
        User::factory()->create();

        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Order::query()->count());
    }
}
