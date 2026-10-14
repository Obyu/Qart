<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Policies\StockLevelPolicy;
use App\Policies\StockMovementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use Tests\TestCase;

class StockPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    private StockLevel $level;

    private StockMovement $movement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();

        $business = Business::factory()->for($this->owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $variant = ProductVariant::factory()->for(
            Product::factory()->for(Category::factory()->for($business))
        )->create();

        $ids = ['outlet_id' => $outlet->id, 'product_variant_id' => $variant->id];
        $this->level = StockLevel::factory()->create($ids + ['quantity' => '5.000']);
        $this->movement = StockMovement::factory()->create($ids + [
            'quantity_delta' => '5.000',
            'quantity_after' => '5.000',
        ]);
    }

    public function test_the_policies_are_discovered_for_their_models(): void
    {
        $this->assertInstanceOf(StockLevelPolicy::class, Gate::getPolicyFor(StockLevel::class));
        $this->assertInstanceOf(StockMovementPolicy::class, Gate::getPolicyFor(StockMovement::class));
    }

    public function test_every_ability_is_declared_explicitly_on_each_policy(): void
    {
        foreach ([StockLevelPolicy::class, StockMovementPolicy::class] as $policy) {
            foreach (['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'] as $ability) {
                $this->assertTrue(method_exists($policy, $ability), "{$policy} must define {$ability}.");
                $this->assertSame(
                    $policy,
                    (new ReflectionMethod($policy, $ability))->getDeclaringClass()->getName(),
                    "{$policy}::{$ability} must be declared on the policy itself."
                );
            }
        }
    }

    public function test_view_any_is_allowed_for_authenticated_users_and_denied_to_guests(): void
    {
        foreach ([StockLevel::class, StockMovement::class] as $model) {
            $this->assertTrue(Gate::forUser($this->owner)->allows('viewAny', $model));
            $this->assertTrue(Gate::forUser($this->other)->allows('viewAny', $model));
            $this->assertFalse(Gate::forUser(null)->allows('viewAny', $model));
        }
    }

    public function test_a_stock_level_can_be_viewed_only_by_the_owner(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $this->level));
        $this->assertFalse(Gate::forUser($this->other)->allows('view', $this->level));
        $this->assertFalse(Gate::forUser(null)->allows('view', $this->level));
    }

    public function test_a_stock_movement_can_be_viewed_only_by_the_owner(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $this->movement));
        $this->assertFalse(Gate::forUser($this->other)->allows('view', $this->movement));
        $this->assertFalse(Gate::forUser(null)->allows('view', $this->movement));
    }

    public function test_create_update_delete_and_delete_any_are_denied_to_everyone_including_the_owner(): void
    {
        $records = [StockLevel::class => $this->level, StockMovement::class => $this->movement];

        foreach ([$this->owner, $this->other] as $user) {
            $gate = Gate::forUser($user);

            foreach ($records as $model => $record) {
                $this->assertFalse($gate->allows('create', $model), "create {$model}");
                $this->assertFalse($gate->allows('deleteAny', $model), "deleteAny {$model}");
                $this->assertFalse($gate->allows('update', $record), "update {$model}");
                $this->assertFalse($gate->allows('delete', $record), "delete {$model}");
            }
        }
    }

    public function test_a_movement_stays_viewable_by_the_owner_after_its_actor_is_deleted(): void
    {
        $actor = User::factory()->create();
        DB::table('stock_movements')->where('id', $this->movement->id)->update(['user_id' => $actor->id]);

        $actor->delete();

        $movement = $this->movement->fresh();
        $this->assertNull($movement->user_id);
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $movement));
        $this->assertFalse(Gate::forUser($this->other)->allows('view', $movement));
    }
}
